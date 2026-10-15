# 新服务器：Docker Compose 一起部署 Lsky 和自建 TG API

一份 Compose 管理两个容器：`lsky-pro` 与 `telegram-bot-api`。Lsky 可拉取 GitHub 已构建镜像，也可选本地源码构建；TG API 使用仓库的轻量 Dockerfile，在预编译的 aiogram 镜像上统一 UID/GID，不在 VPS 从零编译 TDLib。

**这是新服务器部署入口，不是覆盖现有安装的升级脚本。** 现有两份 Compose 已经把图床与 TG 服务部署在一起，可以继续使用原方式；不要直接替换生产 Compose。数据迁移另见文末。

## 1. 关于“DC1 美国 API”

API ID/API Hash 是应用凭据，不是地区选择器。重新申请凭据、选择平台类型或把服务放在美国，不能保证把现有 Bot、账号或文件分配到 DC1。Telegram 账号与文件的数据中心由 Telegram 管理，文件带有自己的 `dc_id`。每个手机号目前只能关联一个 API ID，已有凭据可继续用。

可以在美国服务器运行自建 Bot API，但仍应实测该服务器对实际 Bot/频道的文件上传下载速度，不能仅按个人账号显示的 DC 选机。不要购买来历不明的 API 凭据。

## 2. 目录、端口和凭据

本文示例目录：

| 路径 | 内容 |
|---|---|
| `/opt/lsky/source` | GitHub 仓库源码 |
| `/opt/lsky/stack.env` | 统一部署参数和 Telegram API 凭据，权限600 |
| `/opt/lsky/data` | 图床代码、SQLite、APP_KEY、TG 映射与归档状态 |
| `/opt/lsky/telegram-api/data` | TG API 状态及下载缓存 |

图床只发布本机 `127.0.0.1:8089`，由 Caddy/Nginx 反向代理提供公网 HTTPS。TG API 的8081仅在 Docker 内网使用，不发布公网端口。两个容器中的文件路径均为 `/var/lib/telegram-bot-api`，图床只读。

Bot Token 和频道 ID 仍在图床后台 TG 策略中填写，不填统一 env；API ID/API Hash 只传给 TG API 容器。不要公开 env 或 `docker compose config` 的完整输出。

## 3. 获取仓库和填写参数

需要 Linux、Docker Engine、Docker Compose v2、git。小内存机器宜使用已发布的 Lsky 镜像；本地源码构建会下载依赖并编译扩展，消耗更多内存、磁盘和时间。

先确认新机器没有同名容器/现有数据。以下命令不应用于已有生产目录：

```bash
mkdir -p /opt/lsky
git clone https://github.com/bigyvc/lsky-pro.git /opt/lsky/source
cd /opt/lsky/source
# 部署前查看 Actions，选已通过构建的提交；需要时 git checkout 对应SHA。
# 不覆盖已有凭据文件。
if [ ! -e /opt/lsky/stack.env ]; then
  install -m 600 deploy/stack.env.example /opt/lsky/stack.env
fi
nano /opt/lsky/stack.env
```

必填：

```ini
TELEGRAM_API_ID=你的数字API_ID
TELEGRAM_API_HASH=你的API_HASH
```

其他参数可保留示例默认值；如更改数据目录，两个服务会同时使用 `TG_DATA_DIR`。数据目录保持在源码仓库之外，不会在 `git pull` 或源码构建时混入镜像。

## 4. 方式 A：拉取 Lsky 镜像并一起启动（低配机器适用）

```bash
cd /opt/lsky/source
LSKY_STACK=(docker compose -p lsky --env-file /opt/lsky/stack.env -f deploy/compose.stack.yaml)
"${LSKY_STACK[@]}" config --quiet &&
"${LSKY_STACK[@]}" pull lsky-pro &&
"${LSKY_STACK[@]}" up -d --build
"${LSKY_STACK[@]}" ps
```

这里 `--build` 构建 TG API 的轻量适配镜像；Lsky 使用 `LSKY_IMAGE` 指定的已发布镜像。两者仍是独立容器，统一启停，不把两个服务进程塞进一个容器。

## 5. 方式 B：Lsky 也在本机从源码构建

在同一个 Bash 会话中，或者重新定义数组：

```bash
cd /opt/lsky/source
LSKY_STACK=(docker compose -p lsky --env-file /opt/lsky/stack.env -f deploy/compose.stack.yaml -f deploy/compose.stack-build.yaml)
"${LSKY_STACK[@]}" config --quiet &&
"${LSKY_STACK[@]}" build --pull &&
"${LSKY_STACK[@]}" up -d
"${LSKY_STACK[@]}" ps
```

此模式从仓库根 Dockerfile 构建 Lsky，并从 `tools/telegram-api/Dockerfile` 构建 TG API。需要能访问 Docker Hub、软件包源、Composer/npm 等依赖源。构建失败时不要通过删除数据卷解决。统一管理不等于离线构建。

没有同时提供“从零编译 TDLib”的模式；默认复用 aiogram 维护的非官方预编译打包，可通过 `TELEGRAM_API_BASE_IMAGE` 固定经验证的 digest。

## 6. 图床初始化与 TG 接入

1. 按 [主 README](../README.md#配置反向代理) 为图床域名配置 HTTPS 反代，回源 `127.0.0.1:8089`。默认端口不直接对公网开放。
2. 打开图床域名，完成安装向导，创建管理员；使用默认 SQLite 或按实际数据库部署设置。
3. 检查容器健康和 TG 内网连接：

```bash
docker inspect lsky-pro --format '状态={{.State.Status}} 健康={{if .State.Health}}{{.State.Health.Status}}{{end}}'
docker exec lsky-pro php -r '
$s=@fsockopen("telegram-bot-api",8081,$errno,$errstr,5);
echo $s ? "TG API 内网连接成功\n" : "TG API 内网连接失败\n";
if ($s) fclose($s);
'
```

如果更改了 `LSKY_CONTAINER_NAME`，替换这里的容器名。`depends_on` 仅保证启动顺序，不能替代真实 Bot 登录验证。

4. 创建或保留 Telegram 存储策略：

| 后台字段 | 值 |
|---|---|
| Bot Token | BotFather 生成的 Token |
| 频道 Chat ID | 你自己频道的 ID，Bot 要有发文件权限 |
| Bot API 地址 | `http://telegram-bot-api:8081` |
| TG 本地共享目录 | `/var/lib/telegram-bot-api` |
| 访问域名 | 本次图床 HTTPS 域名 |

5. 若 Bot 曾在官方 API 使用，先按 [官方退出与策略切换流程](r2-archive-telegram.md#5-配置-tg-存储策略或复用现有-bot)执行 logOut 与验证，再启用。新申请 API 凭据不等于新的 Bot。
6. 普通 TG 上传把策略绑定到相应角色组；只用于归档则无需绑定。上传一张小图并实际打开，确认文件读取权限。

## 7. R2 直传、全部归档与缓存清理

这些功能的应用代码已在镜像中，但外部配置不会因为 Compose 启动而自动完成：

- [R2 直传](r2-direct-upload.md)：R2 S3 凭据、存储策略与桶 CORS。
- [9.5GB 全部归档](r2-archive-telegram.md)：Worker 的 BUCKET/变量/路由，实际策略 ID，`--retain=0 --links-ready --enable`。
- [TG 自动缓存清理](r2-archive-telegram.md#12-自动清理-tg-下载缓存)：在 Linux 宿主机运行 `bash /opt/lsky/source/tools/install-telegram-cache-cleaner.sh lsky-pro`。助手会读取本套 Compose 的实际 bind mount，无需改变图床只读挂载。

TG 缓存清理仍是宿主机 systemd timer，不由 `docker compose up` 自动安装。暂停图床时，若需同时暂停清理，执行 `systemctl disable --now lsky-telegram-cache.timer`。

## 8. 更新、停止与备份

新服务器的 GitHub 私有仓库备份及每日定时任务见 [GitHub 备份操作流程](github-backup.md)。旧服务器的备份任务不会自动迁到新服务器。

重新进入 shell 后先按所选方式重新定义 `LSKY_STACK` 数组。保持同一个项目名、同一份 env 和相同数据目录。

方式 A 更新图床：

```bash
cd /opt/lsky/source
git pull --ff-only
LSKY_STACK=(docker compose -p lsky --env-file /opt/lsky/stack.env -f deploy/compose.stack.yaml)
"${LSKY_STACK[@]}" pull lsky-pro && "${LSKY_STACK[@]}" up -d --no-deps lsky-pro
```

方式 B 更新图床：

```bash
cd /opt/lsky/source
git pull --ff-only
LSKY_STACK=(docker compose -p lsky --env-file /opt/lsky/stack.env -f deploy/compose.stack.yaml -f deploy/compose.stack-build.yaml)
"${LSKY_STACK[@]}" build lsky-pro && "${LSKY_STACK[@]}" up -d --no-deps lsky-pro
```

选择方式 B 后不要漏掉 build 补充文件，否则会切回远程镜像配置。固定 commit 的 checkout 应先选定新版本再更新，不直接对 detached HEAD 执行 git pull。

更新 TG API：使用同一数组执行 `build --pull telegram-bot-api`，成功后 `up -d --no-deps telegram-bot-api`。重建不会主动切换 Bot，但服务会短暂不可用。

```bash
# 同一项目下查看或暂停两项服务；不删除数据。
"${LSKY_STACK[@]}" ps
"${LSKY_STACK[@]}" stop
"${LSKY_STACK[@]}" start
```

备份图床 SQLite（在线热备）、图床 `.env`/APP_KEY、`/opt/lsky/stack.env`、选择的 Compose 文件、TG 服务状态及 Worker 配置。保留缓存清理参数。备份含密钥，仅放私有受限存储。不要执行 `down -v` 或删除宿主机数据目录。

## 9. 从现有香港服务器迁到美国

本 Compose 只能解决新机器统一部署，不能自动完成历史数据和 Bot 会话迁移：

- 先备份并保留数据库、APP_KEY、原 TG 策略 ID、原 Bot/频道与 telegram_files/归档映射。更换 Bot 不能直接读取旧 Bot 的全部历史文件。
- 暂停原机器写入与归档，按 Telegram 的本地 API 迁移流程处理旧实例，不能让同一个 Bot 在两个本地服务上同时运行。
- 迁移数据目录和 TG 服务状态时保留权限；不要把新账号的状态与旧 Bot 数据混用。旧缓存并非必须全部搬运，但状态迁移方式需单独核对。
- 恢复后再配置图床域名反代、Worker 的 APP_ORIGIN，实测旧 TG 图片与旧 R2 链接。已有归档目标有身份校验，不随意更换 Bot/频道/共享路径。
- 确认新机可用后再切 DNS、恢复上传与归档；不能仅新建空数据库后切域名。

参考：[API ID 申请](https://core.telegram.org/api/obtaining_api_id)、[数据中心与文件访问](https://core.telegram.org/api/datacenter)、[Bot API 本地服务及迁移](https://github.com/tdlib/telegram-bot-api)。
