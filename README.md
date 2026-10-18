# Lsky Pro：图片、视频与音乐文件托管

基于 [Lsky Pro](https://github.com/lsky-org/lsky-pro) 的自维护 Docker Fork。支持 R2 浏览器直传、自建 Telegram Bot API 存储、R2 达量自动归档、缓存清理和 GitHub 私有备份。

正式分支：`master`。正式镜像：`ghcr.io/bigyvc/lsky-pro-docker:latest`，发布流程包含 amd64 / arm64 构建与运行检查。测试分支只发布`sha-完整提交号`镜像，不覆盖latest。**合并代码后须等待master的Actions成功，latest才会提升到新版本。**

## 一、功能介绍

### 文件上传与管理

- 多用户、角色组、游客上传、相册、标签、搜索和批量管理。
- 图片裁剪、预览、明暗主题及手机触摸操作；站点图标使用相册风格。
- 支持图片、常见视频和音乐格式，保存原始媒体字节，不转码；音视频使用独立占位图和浏览器原生播放器。
- 上传结果支持URL、HTML、BBCode、Markdown等格式；中文链接显示可读名称，复制时保留必要的URL编码。
- 原文件名优先；物理存储同名冲突追加6位码。TG及原图保护链接使用`/记录编码/原文件名.后缀`，旧短链接继续有效。

| 文件类型 | 支持后缀 |
|---|---|
| 图片 | JPG/JPEG、PNG、GIF、WebP、BMP、ICO、TIF等，具体以角色配置为准；不开放SVG上传 |
| 视频 | MP4、MOV、M4V、WebM、HEVC、H265、MKV、AVI、WMV、FLV、TS、M2TS、MTS、3GP |
| 播放列表 | M3U8：仅UTF-8列表和完整HTTPS引用，不上传分片文件夹 |
| 音乐/音频 | MP3、FLAC、WAV、M4A、AAC、OGG、OPUS、WMA、AIFF、AIF、APE |

格式支持表示可以校验和保存，不保证每种浏览器都能播放。HEVC/H.265是编码，MP4/MOV是容器；文件后缀不一定是hevc。详见[视频与音乐格式、资源限制](docs/video-hevc.md)。

### R2、Telegram与自动归档

| 功能 | 行为 |
|---|---|
| R2直传 | 符合条件的管理员R2上传由浏览器直接写入对象存储；每片50MiB、每文件最多6片并发 |
| TG及其他服务器通道 | 文件先传VPS，再由存储适配器写入；每片50MiB、每文件最多6片并发 |
| 角色文件并发 | 控制同时处理的文件数，0表示不设应用层文件并发上限 |
| TG本地API | Lsky与Bot API使用独立容器和相同路径的共享目录，支持大文件读取 |
| R2容量预留 | 协调桶占用、在途上传与新增文件大小，避免应用上传直接冲过配置阈值 |
| 达量归档 | 默认9.5GB触发，持续分批将本图床文件迁出R2；校验TG副本、切换记录后才删除源对象 |
| 原R2链接接续 | Worker优先读R2，迁出后通过应用映射读取TG；无需重新发布已有链接 |
| 自动清理 | 登记TG媒体下载缓存，跳过活跃读取和近期访问，不删除频道文件或TDLib状态 |
| 私有备份 | 在线SQLite快照及关键配置备份到GitHub私有分支，保留TG/归档映射 |

TG不是浏览器直传，仍需VPS中转；R2直传完成时也需后端确认记录及探测媒体元数据。小于等于50MiB的文件只有一片，6并发不表示每个文件都切成六份。

### 可选：频道照片组导入（试验分支）

`test/tg-channel-import`提供独立采集器：保留源帖照片组、每张独立图床链接、指定“频道图片”相册。支持只读数量/体积统计、固定快照的全历史分页、逐帖断点续传、下载限速和后台运行。照片模式可能被TG处理；已知成功记录复用，发送结果不确定时停止。仅用于有使用授权的频道，详见[配置、全量搬运与故障处理](docs/tg-channel-import.md)。

### 可选：DAYV 独立域名套图库

`test/tg-channel-import`新增Multiverse风格的公开图库，目标域名`dayv.org`：套图封面、手机双列、最新/最早/随机、分页、组内浏览和按需大图。复用现有图床与TG照片组，默认关闭，仅公开明确选择的账号/相册；支持补录已搬运的套图，新增分组与配置随SQLite一起备份。详见[部署、公开范围与关闭方法](docs/dayv-gallery.md)。

## 二、安装与配置

以下用于**全新Debian 13服务器、root和Bash**，采用一份Compose管理Lsky和本地TG API。推荐先检查CPU、内存、剩余磁盘和到R2/TG的网络；媒体并发和缓存按实际资源配置。示例使用`dayv.io`与`r2.dayv.io`，请替换为自己的域名。

已有站点从[更新步骤](#11-已有站点更新)开始，不要重新安装。按顺序完成：系统 → 容器 → HTTPS与向导 → TG → 缓存 → R2 → 角色 → Worker → 归档 → 备份。

### 1. 系统与 Docker

以下使用 root、Debian 13 和 Bash。先确认环境：

```bash
cat /etc/os-release
uname -r
free -h
df -h /
```

新机按 [Docker 官方 Debian 安装文档](https://docs.docker.com/engine/install/debian/)配置仓库。已有 Docker 请先检查版本和原部署，不要覆盖软件源或移除运行中的组件。

```bash
apt-get update
apt-get install -y ca-certificates curl git python3 nano util-linux
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
cat > /etc/apt/sources.list.d/docker.sources <<EOF_DOCKER
Types: deb
URIs: https://download.docker.com/linux/debian
Suites: $(. /etc/os-release && echo "$VERSION_CODENAME")
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF_DOCKER
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
systemctl enable --now docker
docker version
docker compose version
```

### 2. 统一部署 Lsky 和 TG API

准备 Telegram 应用凭据 API ID / API Hash，以及独立 Bot、频道。应用凭据从 [my.telegram.org](https://my.telegram.org) 申请；Bot Token 来自 BotFather，Bot 需要频道发消息/文件权限。**API ID / Hash 不能指定或改变 Bot 的数据中心。**

全新目录执行：

```bash
mkdir -p /opt/lsky
git clone https://github.com/bigyvc/lsky-pro.git /opt/lsky/source
cd /opt/lsky/source
if [ ! -e /opt/lsky/stack.env ]; then
  install -m 600 deploy/stack.env.example /opt/lsky/stack.env
fi
nano /opt/lsky/stack.env
```

仅在服务器填写：

```ini
TELEGRAM_API_ID=你的数字API_ID
TELEGRAM_API_HASH=你的API_HASH
```

其他变量先保持模板默认值。不要把填好的 env、Bot Token、PAT 放到公共仓库或日志里。

```bash
cd /opt/lsky/source
LSKY_STACK=(docker compose -p lsky --env-file /opt/lsky/stack.env -f /opt/lsky/source/deploy/compose.stack.yaml)
"${LSKY_STACK[@]}" config --quiet &&
"${LSKY_STACK[@]}" pull lsky-pro &&
"${LSKY_STACK[@]}" up -d --build
"${LSKY_STACK[@]}" ps
curl -I http://127.0.0.1:8089/
```

预期 Lsky 为 healthy；HTTP 302 到 `/install` 是未安装时的正常结果。TG 容器启动不等于 Bot 已经登录，后面还要测试上传与读取。

| 路径或端口 | 用途 |
|---|---|
| `/opt/lsky/source` | 源码与 Compose；git pull 更新这里 |
| `/opt/lsky/stack.env` | TG 应用凭据及部署参数 |
| `/opt/lsky/data` | 应用、SQLite、APP_KEY、图片与 TG 映射、归档记录 |
| `/opt/lsky/telegram-api/data` | TG API 状态及缓存，不能随意清空 |
| `127.0.0.1:8089` | Caddy 回源，非公网监听 |
| `telegram-bot-api:8081` | Docker 内网 API，不发布到公网 |
| `/var/lib/telegram-bot-api` | 两容器内相同路径；Lsky 只读，TG API 可写 |

若要连 Lsky 也本地构建，见 [统一 Compose 详解](docs/docker-stack.md#5-方式-blsky-也在本机从源码构建)。日常使用远程 Lsky 镜像即可；`--build` 在本套默认配置中构建的是 TG API 适配镜像。

### 3. Caddy、HTTPS 与安装向导

把 `dayv.io` 的 A 记录指向本机；没有可用 IPv6 时不要留下错误 AAAA。放通 TCP 80/443。首次排错可先使用 DNS only，确认源站 HTTPS 正常后再决定 Cloudflare 代理；开启代理时使用 Full (strict)。

```bash
apt-get install -y caddy
# 新机示例；已有其他站点应合并配置，而不是覆盖。
cp -an /etc/caddy/Caddyfile /etc/caddy/Caddyfile.before-lsky
cat > /etc/caddy/Caddyfile <<'CADDY'
dayv.io {
    reverse_proxy 127.0.0.1:8089
}
CADDY
caddy fmt --overwrite /etc/caddy/Caddyfile
caddy validate --config /etc/caddy/Caddyfile && systemctl reload caddy
curl -I https://dayv.io/
```

访问 `https://dayv.io/install` 完成安装，使用默认 SQLite，创建管理员。之后不要再运行安装向导、替换 APP_KEY 或删除 database.sqlite。HTTPS 302 跳转安装页是正常现象；419 通常要检查会话、Cookie、反代与旧标签页，不要通过关闭 CSRF 修复。

### 4. TG 策略与游客上传

先确认 API 内网可达：

```bash
docker exec lsky-pro php -r '
$s=@fsockopen("telegram-bot-api",8081,$errno,$errstr,5);
echo $s ? "TG API 内网连接成功\n" : "TG API 内网连接失败\n";
if ($s) fclose($s);
'
```

后台新建 Telegram 策略：

| 字段 | 填写 |
|---|---|
| Bot Token | 原 Bot Token |
| 频道 Chat ID | 实际频道 ID，通常为 `-100…` |
| **Bot API 地址** | **`http://telegram-bot-api:8081`** |
| **本地共享目录** | **`/var/lib/telegram-bot-api`** |
| 图床访问域名 | `https://dayv.io` |

**不能只填 Token/频道而漏改 API 地址与共享目录。** 官方 API 与自建 API 的大小和读取方式不同；本地 API 的绝对路径必须能被 Lsky 在同一路径读取。

- 全新、从未使用官方 API 的 Bot 可直接接入本地 API。
- 已用过官方 API 的 Bot，先按 [官方退出与本地登录验证](docs/r2-archive-telegram.md#5-配置-tg-存储策略或复用现有-bot)执行 logOut、getMe/getChat，再切策略。不要让同一 Bot 在两台本地 API 实例上同时运行。
- 在角色组里给游客绑定 TG 策略，启用游客上传。策略存在但角色组未绑定，会提示“没有可用的存储”。
- 用无痕窗口上传一张小图，然后打开返回的原图链接。**上传成功与读取成功必须分别验证。** TG 原图始终经图床输出，不依赖原图保护开关，不公开 Bot Token。原文件链接是持有地址即可访问的链接，不是登录鉴权。

用下面命令读取实际策略 ID，不能照抄旧服务器 ID：

```bash
docker exec lsky-pro php /var/www/html/artisan r2:archive-config --list
```

示例新站是 **TG=2，R2=3**；已有站点可能不同，必须以实际输出为准。输出末尾 `[]` 仅表示尚未创建归档策略。

### 5. 启用 TG 缓存清理

```bash
bash /opt/lsky/source/tools/install-telegram-cache-cleaner.sh lsky-pro
systemctl list-timers lsky-telegram-cache.timer --no-pager
journalctl -u lsky-telegram-cache.service -n 20 --no-pager
```

默认每5分钟检查，清理登记后超过1小时的 TG 下载缓存；容量超过2GiB或剩余磁盘不足2GiB时，尝试清理符合最小年龄的旧缓存。正在读取的文件跳过。它只处理登记的媒体文件，不删除频道原图、数据库、TDLib 状态、未登记文件或活动上传。

`registered:0` 表示没有登记项，**不代表磁盘没有缓存**；`removed:0` 不等于清理失败。分片临时文件另由容器中的小时任务清理，任务保留期24小时。详见 [清理范围与参数](docs/r2-archive-telegram.md#12-自动清理-tg-下载缓存)。30GB磁盘可按需将容量阈值设为15GiB，并用 `TG_CACHE_TTL=0` 关闭单纯按1小时过期清理；默认不会替已有机器自动更改参数。

### 6. R2 凭据、策略与跨域

Cloudflare → R2 → 创建图床桶 → 管理 R2 API Tokens，创建 **Object Read & Write** 凭据，只授权目标桶，保存 Access Key ID 与 Secret Access Key。它不是普通 Cloudflare 全局 API Key。

后台新增 AWS S3 策略：

| 字段 | 填写 |
|---|---|
| Endpoint | 控制台给出的 HTTPS S3 endpoint，通常为 `https://账户ID.r2.cloudflarestorage.com` |
| Region | `auto` |
| Bucket | 实际桶名 |
| Access Key / Secret Key | 刚创建的 R2 S3 凭据 |
| Bucket Endpoint | 关闭，使用标准账户 endpoint |
| 访问域名 | 桶已绑定的 `https://r2.dayv.io` |

先在 R2 桶里绑定自定义域名并验证可用。若桶有特殊地域约束，使用控制台提供的真实 endpoint。

桶 CORS 示例，仅允许本图床：

```json
[
  {
    "AllowedOrigins": ["https://dayv.io"],
    "AllowedMethods": ["PUT"],
    "AllowedHeaders": ["Content-MD5", "Content-Type"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }
]
```

将 R2 策略绑定管理员角色组；游客继续使用 TG。先上传一张小图，打开 R2 返回链接。管理员选择符合条件的标准 R2 策略后自动直传，不再提供服务器中转选择框；失败不会静默切换为服务器传输。

### 7. 角色、命名、分片与并发

| 设置 | 配置与含义 |
|---|---|
| 管理员最大文件大小 | 512MiB；后台以 KB 填写时为 **524288**，不是总容量数值 |
| 游客最大文件大小 | 示例50MiB；KB 字段填 **51200** |
| 保存质量/格式 | 管理员 R2 直传：质量100，不转换格式，关闭水印与审查；后端会检查 |
| 路径命名 | `{uid}` 时游客目录为 `0`，管理员如 `1`；直接填 `0` 也是有效目录 |
| 文件命名 | `{filename}`：优先原文件名；重名但不同内容加 `-` 和6位十六进制编码 |
| 角色“并发上传” | 同时处理的**文件数**；0 不设应用层文件并发上限，仍受浏览器、后端资源和任务预留限制 |
| R2 分片 | 每片50MiB，每个文件最多6片并发，文件字节不经过 VPS |
| TG 等服务器通道 | 每片50MiB，每个文件最多6片并发，VPS 接收并交给存储策略 |

所有大小的文件走统一分片流程；50MiB以内只有1片，并不会强行分成6片。过程统一显示“上传中…百分比”，校验时为0%，传输完成后确认记录时保持99%，保存成功才显示链接。

音视频需在各角色组中单独勾选允许的后缀，并关闭该角色的图片审查和水印；不是更新后自动放开。视频/音频硬上限512MiB，M3U8列表最多1MiB。R2直传还要求保存质量100、不转换格式。详见[媒体说明](docs/video-hevc.md)。

浏览器提供的原文件名若本身就是 UUID，原名自然很长，程序不会替它改成 IMG_xxxx。TG 重复内容可复用有效映射；映射缺失时重新上传补回，不复制已知无映射的旧记录。新上传不会覆盖同名、不同内容的旧 TG 图片。

### 8. Worker 保留原 R2 链接

归档会释放 R2 原对象，必须先部署 Worker 才能让旧链接转向 TG。

1. 创建 Worker，例如 `lsky-dayv-io-archive`，使用 [`tools/r2-archive-worker.mjs`](tools/r2-archive-worker.mjs) 的完整代码。
2. 添加 R2 绑定 **BUCKET**，选择此图床正在使用的**同一个桶**。
3. 添加变量 `APP_ORIGIN=https://dayv.io`、`SOURCE_STRATEGY_ID=3`（替换为实际 R2 策略 ID）。
4. 添加 **Worker Route**：`r2.dayv.io/*`，区域 dayv.io，Fail closed；按 Cloudflare 路由要求使用代理 DNS。
5. **保留 R2 桶已有的自定义域名**，这里添加的是路由，不是改绑 Worker 自定义域名。
6. 打开 `https://r2.dayv.io/.__lsky_archive_probe`，示例预期：

```json
{"lsky_r2_archive":1,"source":3}
```

探针 JSON 正常还不等于桶已校验。后续启用命令会取现有 R2 图片，比较 Worker 绑定对象的 ETag/大小；因此桶里必须先有一张已入库的小图。Worker 不填写 Bot Token、R2 密钥或 API Hash。

### 9. 启用 9.5GB 全部归档

先暂停上传，完成或取消已有 R2 上传任务；确认 TG 上传及读取、小图 R2 链接和 Worker 探针均正常。**以下示例 ID 为 R2=3、TG=2；执行前必须用 --list 核对。**

```bash
docker exec -u www-data lsky-pro php /var/www/html/artisan r2:archive-config \
  --source=3 --target=2 \
  --trigger=9500000000 --retain=0 --links-ready --enable &&
docker exec -u www-data lsky-pro php /var/www/html/artisan r2:archive --dry-run
```

保存成功后，容器每5分钟检查。容量触发后分批持续归档至本图床全部图片迁出 R2，不会仅降到8.5GB就停止；不删除桶里不属于图床的对象。先校验 TG 实际文件，再切图片记录，最后释放 R2 原对象。归档映射和 Worker 要长期保留。新归档文档在TG频道中显示为`六位记录编码-原文件名`，例如`zGBFCE-歌曲.flac`；内部映射路径不变，已有TG文档不会追溯改名。

`used` 是桶占用字节，`reserved` 是待写入预留；`migrated=0, failed=0` 在未达阈值的预检中正常。**dry-run 不执行迁移，不能代替实际迁移与旧链接验收。** 9.5GB 是本项目的桶阈值，不是对整个 Cloudflare 账号费用的保证。

停用后续自动迁移：

```bash
docker exec -u www-data lsky-pro php /var/www/html/artisan r2:archive-config --source=3 --disable
```

不要删除已有映射、TG 目标或 Worker。完整状态与故障恢复见 [归档说明](docs/r2-archive-telegram.md)。

### 10. GitHub 每日备份

新服务器不会继承旧服务器 cron。先创建或沿用 **私有** `bigyvc/lsky-backup`，细粒度 PAT 只授权此仓库 Contents Read and write。

```bash
install -d -m 700 /opt/lsky/backup
printf '%s\n' 'BACKUP_REPO=bigyvc/lsky-backup' > /opt/lsky/backup/config
chmod 600 /opt/lsky/backup/config
if [ ! -e /opt/lsky/backup/github-token ]; then
  install -m 600 /dev/null /opt/lsky/backup/github-token
fi
nano /opt/lsky/backup/github-token
bash /opt/lsky/source/tools/backup-github.sh
```

确认“备份成功”，在私有仓库选择 **dayv-io 分支**查看备份和 SHA256SUMS，无需合并 PR。脚本在线备份 SQLite 并校验完整性，包含 `.env/APP_KEY`、TG 映射、归档记录、stack.env、Compose、Caddy 及定时任务配置；先核对实际数据库配置，MySQL 等非默认部署会停止并提示。

备份包**未加密且包含密钥**，必须私有。每次只保留该分支最新快照，不提供多版本回滚；不改旧站 main 分支，不打包 R2/TG 图片、TG API 状态或缓存。PAT 不进入备份。

手动成功后，按 [每日03:00定时任务](docs/github-backup.md#3-每天北京时间-0300)安装 service/timer，再检查：

```bash
systemctl list-timers lsky-github-backup.timer --no-pager
journalctl -u lsky-github-backup.service -n 30 --no-pager
```

时间明确为 Asia/Shanghai，不依赖 VPS 当前时区。任务不自带失败提醒，留意 PAT 期限与日志。恢复时先校验 SHA256，在独立目录检查包，停止写入后再恢复；不要向运行中的 SQLite 直接覆盖解包。详见 [恢复流程与备份边界](docs/github-backup.md#4-恢复前验证)。

### 11. 已有站点更新

先等待目标master提交的[Actions](https://github.com/bigyvc/lsky-pro/actions)全部成功。`git pull`只更新宿主源码和工具，不会更新运行容器。暂停上传并完成备份后，再拉镜像重建Lsky。

**统一Compose部署**使用下列命令；不要用于HK旧式多文件部署：

```bash
(
set -e
bash /opt/lsky/source/tools/backup-github.sh
git -C /opt/lsky/source pull --ff-only origin master
STACK=(docker compose -p lsky --env-file /opt/lsky/stack.env \
  -f /opt/lsky/source/deploy/compose.stack.yaml)
"${STACK[@]}" config --quiet
"${STACK[@]}" pull lsky-pro
"${STACK[@]}" up -d --no-deps --wait --wait-timeout 180 lsky-pro
docker exec -u www-data -w /var/www/html lsky-pro php artisan route:clear
docker exec -u www-data -w /var/www/html lsky-pro php artisan view:clear
docker inspect lsky-pro --format '镜像={{.Config.Image}} 状态={{.State.Status}} 健康={{if .State.Health}}{{.State.Health.Status}}{{end}}'
)
```

上面假定stack.env的LSKY_IMAGE使用latest。**已有测试覆盖文件时，不能直接省略它来更新**：

| 部署方式 | 应保留的参数 |
|---|---|
| SJC统一部署的测试版 | `--env-file /opt/lsky/stack.env -f /opt/lsky/source/deploy/compose.stack.yaml -f /opt/lsky/docker-compose.media-test.yaml` |
| HK旧站测试版 | `-f /opt/lsky/docker-compose.yaml -f /opt/lsky/docker-compose.archive.yaml -f /opt/lsky/docker-compose.hevc.yaml` |

先保存当前镜像标签和全部Compose文件。若要从固定SHA转回正式版，等master发布成功后，将**专用镜像覆盖文件中**的image改为`ghcr.io/bigyvc/lsky-pro-docker:latest`，再使用原来全部Compose参数pull/up。环境变量LSKY_IMAGE不能覆盖Compose文件里写死的image。自定义配置不要整文件覆盖；备份脚本未必包含手工新增的覆盖文件，需另行留存。

entrypoint自动同步代码及执行迁移，保留数据库、APP_KEY、策略、归档记录和站点数据。同步会替换手工热补丁，修复必须进入镜像。普通Lsky更新不必重建TG API，不重设现有缓存阈值，也不需要重新配置归档。不要使用`down -v`或删除data。

更新后用无痕窗口验证：游客上传与读取、管理员R2上传、中文名称复制、同名不同内容、音视频进度拖动、旧链接。HEAD只返回记录元信息，TG文件实际可读性用GET播放或下载验证。真实归档需另选一个小文件验证TG副本、R2删除和原链接回退，不能把dry-run当作迁移成功。

## 三、FAQ

### 上传角色和存储策略有什么关系？

策略必须绑定当前角色组。管理员、普通用户和游客分别配置类型及限额；新建策略不等于已允许游客使用。`--list`末尾的`[]`表示尚无归档策略，不是存储策略丢失。

### 为什么所有文件都走分片，但小文件没有并发？

统一上传流程以50MiB为每片上限，小文件只有一片。文件并发控制同时上传多少文件，分片并发控制一个文件同时传多少片；角色0不限制应用层文件数，浏览器连接数、带宽、服务器资源及任务预留仍会限制实际速度。

### TG上传是直传吗？能放到Cloudflare或GitHub吗？

TG仍经过VPS和Bot API。Worker与Actions不适合作为长期运行、保存TDLib状态和本地缓存的Bot API服务。可以部署到另一台VPS，但当前共享目录方案需要两边有一致且可读的文件路径，不能只改一个API地址就迁移。

### API ID/API Hash能指定TG数据中心吗？

不能。它们用于启动Bot API应用，不负责选择Bot数据中心，也不能替代Bot Token和频道权限。不要在两台本地API实例同时登录同一Bot。

### TG上传成功，链接却返回503？

分别检查API内网连接、共享目录可读、数据库映射和实际GET读取。`0`是合法目录，`0/文件名`与`文件名`是不同映射；不要批量改数据库或删除telegram_files。新版已修复零目录误判和已知缺映射补传。排查入口见[TG说明](docs/telegram-storage.md)。

### HTTP 200为什么还显示失败或一直99%？

HTTP状态与业务JSON状态不同。99%表示字节已传完，但后端尚未确认保存；检查complete响应、容器和Laravel日志。不要手工把aborted改recorded，也不要关闭格式/CSRF校验。相机JPEG头可有界读取到4MiB，超过识别能力会明确失败。

### 中文链接里的%E5等是乱码吗？

不是，是URL编码。页面显示可读中文，复制保留必要编码；TG及原图保护URL为`/记录编码/原文件名.后缀`，R2关闭保护时沿用对象直链。原文件名自带UUID时仍保留；同名防覆盖的6位后缀只影响必要的物理命名。

### 为什么TG里仍是纯编码文件名？

新版本新发送的归档文档使用`记录编码-原文件名`。旧文档不自动改名；重试发现已有TG副本会验证复用，不为改名重复发送。先检查运行镜像是否包含修复，再选未归档文件测试。

### 原图保护等于禁止下载或私有访问吗？

不等于。原文件URL是持有链接即可读取的地址。独立分享页和下载按钮开关已移除，浏览器原生播放器、源文件链接仍可能保存文件。

### HEVC为什么上传后是MP4？为什么某些格式不能网页播放？

MP4/MOV是容器，HEVC是编码，保留原后缀不会转换内容。浏览器支持取决于容器、编码和设备；不兼容格式可用外部播放器。M3U8只是列表，不包含TS，当前不支持文件夹整包上传，也不抓取外部分片和密钥。

### 9.5GB是每月刷新吗？达到后只降到8.5GB吗？

这里是桶的当前存储阈值，不是每月清零的上传额度。`--retain=0`使触发后的归档周期持续到本图床文件全部迁出，保留桶内无关对象。它不是对整个Cloudflare账号计费的保证；Worker、映射和TG文件需要继续保留。

### 看到Worker探针JSON就表示旧链接可靠了吗？

探针只证明路由和变量生效。启用归档还会用真实对象比对绑定桶的ETag和大小；完整验收应验证归档后R2源对象已删除、原URL仍能GET读取。不要直接删除映射或停用Worker。

### registered=0或removed=0是不是清理失败？缓存能设15GiB吗？

不一定。清理只处理登记的完整媒体下载，未登记文件和TG状态不计入额度。参数位于`/etc/default/lsky-telegram-cache`；30GB盘且空间足够时可参考：

```ini
TG_CACHE_TTL=0
TG_CACHE_MAX_BYTES=16106127360
TG_CACHE_MIN_FREE=2147483648
TG_CACHE_MIN_AGE=300
```

TTL=0仅关闭按年龄过期，容量/剩余空间压力仍会触发清理，近5分钟访问和活动读取受保护。15GiB不是预留或硬配额；小磁盘要按实际剩余空间降低，不能照抄。

### 更新后打不开，或页面还是旧的？

确认容器healthy、实际镜像和域名响应，再清理Blade缓存及浏览器站点缓存。无痕正常通常应先检查旧Cookie/缓存。419先检查会话、HTTPS、反代与旧标签，不通过关闭CSRF处理。首次302到/install正常，旧站突然到/install则检查挂载和安装状态，别直接重装。

### 备份成功就能完整搬站吗？

备份保留SQLite、APP_KEY和配置，不包含媒体字节、TG API状态、Cloudflare控制台Worker配置或快照后的变化。需另存自定义Compose覆盖文件和Worker绑定信息，在独立目录校验后做恢复演练。GitHub备份分支只留最新快照，包未加密且含密钥，必须私有，PAT不能提交到公开仓库。

## 详细文档与许可

- [统一Compose与本地构建](docs/docker-stack.md)
- [TG策略与共享目录](docs/telegram-storage.md)
- [R2直传](docs/r2-direct-upload.md)、[服务器分片](docs/chunk-upload.md)
- [视频与音乐格式](docs/video-hevc.md)
- [归档及缓存](docs/r2-archive-telegram.md)、[GitHub备份与恢复](docs/github-backup.md)
- [站点图标](docs/site-icons.md)、[功能及上游差异](docs/project-reference.md)
- [部署复盘](docs/deployment-review-2026-10-16.md)、[媒体分支审查记录](docs/branch-review-2026-10-17.md)

非官方自维护Fork。保留本仓库及上游许可文件，遵守相应许可与依赖声明。感谢Lsky Pro、原Docker打包项目、Telegram Bot API、aiogram及相关开源项目。
