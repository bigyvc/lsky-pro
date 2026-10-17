# Lsky Pro：R2 直传 + Telegram 存储 + 自动归档

> **实验分支 `test/video-hevc`**：加入更多视频格式、M3U8列表、视频分享页及下载入口开关，不更新正式站点。先阅读 [分支范围、启用与限制](docs/video-hevc.md)。

基于 [Lsky Pro](https://github.com/lsky-org/lsky-pro) 的自维护 Docker Fork，保留多用户、图片标签、裁剪、明暗主题及手机端支持。

镜像：`ghcr.io/bigyvc/lsky-pro-docker:latest`（amd64 / arm64）。TG API 与 Lsky 是两个独立容器，用一份 Compose 管理。TG API 基于 aiogram 的非官方预编译镜像适配共享目录权限，不在 VPS 上从零编译 TDLib。

本文按照 **2026-10-16 新服务器的实际搭建顺序**整理：Debian → Docker → 两个容器 → HTTPS 与安装 → TG → 缓存清理 → R2 → Worker → 归档 → GitHub 备份。`dayv.io` 和 `r2.dayv.io` 是示例域名，其他部署请统一替换。

**已有站点从 [更新步骤](#11-已有站点更新) 开始，不要重新安装或清空数据库。** 代码提交、Actions 成功、服务器更新、功能配置完成是四个不同步骤。

## 目录

1. [系统与 Docker](#1-系统与-docker)
2. [统一部署 Lsky 和 TG API](#2-统一部署-lsky-和-tg-api)
3. [Caddy、HTTPS 与安装向导](#3-caddyhttps-与安装向导)
4. [TG 策略与游客上传](#4-tg-策略与游客上传)
5. [启用 TG 缓存清理](#5-启用-tg-缓存清理)
6. [R2 凭据、策略与跨域](#6-r2-凭据策略与跨域)
7. [角色、命名、分片与并发](#7-角色命名分片与并发)
8. [Worker 保留原 R2 链接](#8-worker-保留原-r2-链接)
9. [启用 9.5GB 全部归档](#9-启用-95gb-全部归档)
10. [GitHub 每日备份](#10-github-每日备份)
11. [已有站点更新](#11-已有站点更新)
12. [验收与今天的问题排查](#12-验收与今天的问题排查)

## 1. 系统与 Docker

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

## 2. 统一部署 Lsky 和 TG API

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

## 3. Caddy、HTTPS 与安装向导

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

## 4. TG 策略与游客上传

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
- 用无痕窗口上传一张小图，然后打开返回的原图链接。**上传成功与读取成功必须分别验证。** TG 原图始终经图床输出，不依赖原图保护开关，不公开 Bot Token。

用下面命令读取实际策略 ID，不能照抄旧服务器 ID：

```bash
docker exec lsky-pro php /var/www/html/artisan r2:archive-config --list
```

本次新站是 **TG=2，R2=3**；香港旧站 TG=4，不要混用。输出末尾 `[]` 仅表示尚未创建归档策略。

## 5. 启用 TG 缓存清理

```bash
bash /opt/lsky/source/tools/install-telegram-cache-cleaner.sh lsky-pro
systemctl list-timers lsky-telegram-cache.timer --no-pager
journalctl -u lsky-telegram-cache.service -n 20 --no-pager
```

默认每5分钟检查，清理登记后超过1小时的 TG 下载缓存；容量超过2GiB或剩余磁盘不足2GiB时，尝试清理符合最小年龄的旧缓存。正在读取的文件跳过。它只处理登记的媒体文件，不删除频道原图、数据库、TDLib 状态、未登记文件或活动上传。

`registered:0` 表示没有登记项，**不代表磁盘没有缓存**；`removed:0` 不等于清理失败。分片临时文件另由容器中的小时任务清理，任务保留期24小时。详见 [清理范围与参数](docs/r2-archive-telegram.md#12-自动清理-tg-下载缓存)。30GB磁盘可按需将容量阈值设为15GiB，并用 `TG_CACHE_TTL=0` 关闭单纯按1小时过期清理；默认不会替已有机器自动更改参数。

## 6. R2 凭据、策略与跨域

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

将 R2 策略绑定管理员角色组；游客继续使用 TG。先上传一张小图，打开 R2 返回链接。管理员选择标准 R2 策略后自动直传，不再提供服务器中转选择框；失败不会静默切换为服务器传输。

## 7. 角色、命名、分片与并发

| 设置 | 本次配置与含义 |
|---|---|
| 管理员最大文件大小 | 512MiB；后台以 KB 填写时为 **524288**，不是总容量数值 |
| 游客最大文件大小 | 示例50MiB；KB 字段填 **51200** |
| 保存质量/格式 | 管理员 R2 直传：质量100，不转换格式，关闭水印与审查；后端会检查 |
| 路径命名 | `{uid}` 时游客目录为 `0`，管理员如 `1`；直接填 `0` 也是有效目录 |
| 文件命名 | `{filename}`：优先原文件名；重名但不同内容加 `-` 和6位十六进制编码 |
| 角色“并发上传” | 同时处理的**文件数**；0 不设应用层文件并发上限，仍受浏览器、后端资源和任务预留限制 |
| R2 分片 | 每片50MiB，每张图最多6片并发，文件字节不经过 VPS |
| TG 等服务器通道 | 每片50MiB，每张图最多3片并发，VPS 接收并交给存储策略 |

所有大小的图片走统一分片流程；50MiB以内只有1片，并不会强行分成6片。过程统一显示“上传中…百分比”，校验时为0%，传输完成后确认记录时保持99%，保存成功才显示链接。

浏览器提供的原文件名若本身就是 UUID，原名自然很长，程序不会替它改成 IMG_xxxx。TG 重复内容可复用有效映射；映射缺失时重新上传补回，不复制已知无映射的旧记录。新上传不会覆盖同名、不同内容的旧 TG 图片。

## 8. Worker 保留原 R2 链接

归档会释放 R2 原对象，必须先部署 Worker 才能让旧链接转向 TG。

1. 创建 Worker，例如 `lsky-dayv-io-archive`，使用 [`tools/r2-archive-worker.mjs`](tools/r2-archive-worker.mjs) 的完整代码。
2. 添加 R2 绑定 **BUCKET**，选择此图床正在使用的**同一个桶**。
3. 添加变量 `APP_ORIGIN=https://dayv.io`、`SOURCE_STRATEGY_ID=3`（替换为实际 R2 策略 ID）。
4. 添加 **Worker Route**：`r2.dayv.io/*`，区域 dayv.io，Fail closed；按 Cloudflare 路由要求使用代理 DNS。
5. **保留 R2 桶已有的自定义域名**，这里添加的是路由，不是改绑 Worker 自定义域名。
6. 打开 `https://r2.dayv.io/.__lsky_archive_probe`，本次预期：

```json
{"lsky_r2_archive":1,"source":3}
```

探针 JSON 正常还不等于桶已校验。后续启用命令会取现有 R2 图片，比较 Worker 绑定对象的 ETag/大小；因此桶里必须先有一张已入库的小图。Worker 不填写 Bot Token、R2 密钥或 API Hash。

## 9. 启用 9.5GB 全部归档

先暂停上传，完成或取消已有 R2 上传任务；确认 TG 上传及读取、小图 R2 链接和 Worker 探针均正常。**本次 ID 是 R2=3、TG=2，其他部署先执行 --list。**

```bash
docker exec -u www-data lsky-pro php /var/www/html/artisan r2:archive-config \
  --source=3 --target=2 \
  --trigger=9500000000 --retain=0 --links-ready --enable &&
docker exec -u www-data lsky-pro php /var/www/html/artisan r2:archive --dry-run
```

保存成功后，容器每5分钟检查。容量触发后分批持续归档至本图床全部图片迁出 R2，不会仅降到8.5GB就停止；不删除桶里不属于图床的对象。先校验 TG 实际文件，再切图片记录，最后释放 R2 原对象。归档映射和 Worker 要长期保留。

`used` 是桶占用字节，`reserved` 是待写入预留；`migrated=0, failed=0` 在未达阈值的预检中正常。**dry-run 不执行迁移，不能代替实际迁移与旧链接验收。** 9.5GB 是本项目的桶阈值，不是对整个 Cloudflare 账号费用的保证。

停用后续自动迁移：

```bash
docker exec -u www-data lsky-pro php /var/www/html/artisan r2:archive-config --source=3 --disable
```

不要删除已有映射、TG 目标或 Worker。完整状态与故障恢复见 [归档说明](docs/r2-archive-telegram.md)。

## 10. GitHub 每日备份

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

## 11. 已有站点更新

此处仅适用于上述**统一 Compose**。香港旧站的 `/opt/lsky/docker-compose.yaml` + `docker-compose.archive.yaml` 请继续使用原文件，不能混用。先等待目标提交对应的 Actions 成功，再暂停上传，备份后更新：

```bash
bash /opt/lsky/source/tools/backup-github.sh &&
git -C /opt/lsky/source pull --ff-only origin master &&
docker compose -p lsky --env-file /opt/lsky/stack.env \
  -f /opt/lsky/source/deploy/compose.stack.yaml pull lsky-pro &&
docker compose -p lsky --env-file /opt/lsky/stack.env \
  -f /opt/lsky/source/deploy/compose.stack.yaml up -d --no-deps lsky-pro
```

```bash
docker inspect lsky-pro --format '状态={{.State.Status}} 健康={{if .State.Health}}{{.State.Health.Status}}{{end}}'
docker exec -u www-data -w /var/www/html lsky-pro php artisan view:clear
curl -I https://dayv.io/
```

entrypoint 会同步新代码并执行迁移，保留数据库、`.env` 和站点数据；这也会替换以前手工打过的代码热补丁，因此修复须先进入正式镜像。更新后强制刷新浏览器，确认使用新脚本。`git pull` 只更新 source，不会自己更新运行中的图床。不要用 `down -v` 或清空 data 解决问题。

TG API 只在需要更新时单独 build/up；不要为普通 Lsky 更新重建或退出 Bot。更多模式与历史数据迁移见 [Docker Stack](docs/docker-stack.md)。

## 12. 验收与今天的问题排查

每一步验收成功再进入下一步，不把某一层正常等同于全部正常。

| 现象 | 检查与处理 |
|---|---|
| 302 到 /install | 首次安装正常；已有站点突然出现则检查挂载目录与安装状态，不要直接重装 |
| 没有可用的存储 | 策略要绑定当前角色组；新增策略不等于绑定 |
| TG 上传成功但原图503 | 区分 API 内网、共享目录、数据库映射三层；确认图片 pathname 与 telegram_files.path 完全一致 |
| `path="0"`，映射为 `0/图片.png` | `0` 是合法目录；模型读取和保存路径均已修复，空目录及重复分隔符也统一处理 |
| 重复上传仍返回坏链接 | 新版会先检查 TG 映射；缺失时补传。不能凭同哈希图片记录就认定文件存在 |
| 同名图片变成同一张图 | 新版 TG 上传保留原名，冲突追加6位编码，并锁定名称分配，避免覆盖原映射 |
| `cannot call methods on fileupload prior to initialization` | 新版组件挂在稳定容器；旧版选择文件后 input 被替换。更新镜像、view:clear、强制刷新 |
| HTTP 200 但显示失败 | 还要看 JSON 的 status/message；200 不保证业务成功 |
| 相机 JPG 卡在完成阶段 | 新版 JPEG 最多读4MiB文件头识别大型EXIF/ICC，异常任务会报错；仍无法识别则保留校验，不绕过 |
| 一直显示99% | 文件传输完，后台尚未成功确认；检查 complete 响应、日志和任务状态，不手工改 phase |
| 角色并发0仍一张张传 | 新版文件调度已读取角色设置；分片并发与文件并发是两个维度 |
| API目录可读但“映射不存在” | 文件权限正常不能代替映射检查；不要换 Bot 或删 telegram_files |
| `registered:0 / removed:0` | 清理服务可正常运行但无登记项；不能推断磁盘无缓存 |
| 归档 --list 末尾 [] | 尚未配置归档，不是 R2/TG 策略不存在 |
| 更新后未见新页面 | 确认镜像已构建、容器确实更新、Blade缓存与浏览器缓存已刷新 |
| 构建 md5sum 失败 | 源码有意修改时同步 Dockerfile 校验值；不要删除校验绕过问题 |

完整审查范围、修复与回归用例见 [2026-10-16 部署复盘](docs/deployment-review-2026-10-16.md)。

新机最低验收：游客 TG 上传+打开链接、管理员 R2 上传+打开链接、相机 JPG、同名不同内容、5张小图、刷新重试、Worker绑定验证、归档预检、首次备份和 timer。实际归档与恢复演练需要单独执行，测试通过不等同于你的线上服务器已验证。

## 更多说明与许可

- [浏览器与 iPhone 主屏幕图标](docs/site-icons.md)

- [统一部署与本地构建](docs/docker-stack.md)
- [TG 策略与共享目录](docs/telegram-storage.md)
- [R2 直传](docs/r2-direct-upload.md)、[服务器分片上传](docs/chunk-upload.md)
- [归档与缓存清理](docs/r2-archive-telegram.md)、[GitHub 备份](docs/github-backup.md)
- [功能、界面、旧部署及上游差异参考](docs/project-reference.md)

这是非官方自维护 Fork；保留仓库及上游许可文件，遵守对应许可与依赖声明。感谢 Lsky Pro 上游、原 Docker 打包项目、Telegram Bot API、aiogram 与相关开源项目。
