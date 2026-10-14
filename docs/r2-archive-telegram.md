# R2 9.5GB 自动迁移到自建 Telegram Bot API

代码提供归档和部署文件，未填写服务器策略 ID、Telegram API 凭证、部署 Worker 并启用策略之前，不会自动迁移或删除图片。迁移不需要更改游客的现有 TG 策略。

## 行为

- 9.5GB 是 **9,500,000,000 字节**，统计整个物理 R2 桶，包括数据库没有登记的对象；不是“每月可刷新容量”。另计正在上传的容量预留。新上传预计超出阈值时先拒绝并请求归档，稍后可重试。
- 每5分钟启动一轮，单进程、串行，每轮最多25个对象。达到阈值或收到容量请求时，默认按最旧图片持续迁移，直到全部图床管理的图片迁出 R2。每轮达到对象数/时间限制后，下一轮继续，即使桶容量已低于触发阈值。未登记对象保留。
- 原图以完整文件上传到自建 TG API，支持本项目512MiB上限。先校验 R2 文件，再通过 TG getFile 下载路径逐字节验证长度、MD5、SHA1；切换数据库记录后，检查 R2 ETag 未变化才删除源对象。读取失败、哈希不一致、服务不可用时保留 R2 源文件。
- 迁移状态在数据库中，中断后续跑；共享物理文件的全部图片记录一起切换。图片 ID、相册、标签、原文件名、容量计数不变。TG 输出直接流式读取原图，不做大图解码，支持单段 Range。
- 旧 `r2.dayv.cc` URL 由 Worker 接管。未迁移对象从 R2 绑定读取，已迁移对象通过图床流式输出；不会把 Bot Token 放进 URL。已归档的旧路径永久预留，不再给新直传对象复用。
- 不自动迁移未登记对象，不清理其他工具创建的文件。若这些对象占据大部分桶空间，归档可能无法释放到目标容量。Bot API 的下载缓存占用 VPS 磁盘；复制前检查足够存放暂存文件和下载缓存，并至少留下512MiB余量，空间不足时停止此对象迁移。不要宣称TG可让VPS无限归档而不占缓存磁盘；350MB 图片仍需要本地磁盘空间和网络传输，归档不会让 TG 达到 R2 的直连访问速度。
- 容量与归档锁要求所有图床实例共享 storage/app。外部程序仍可写入同一桶；请勿在迁移期间覆盖同一对象。R2 完成/删除前的外部写入竞争不能由应用文件锁完全阻止。

## 1. 自建 TG API

使用 **独立归档 Bot/频道**，不要把游客正在使用的官方 API Bot 直接迁走。申请 Telegram API ID/API Hash：https://my.telegram.org 。归档 Bot Token 由 BotFather 创建，Bot 加入归档频道并授予发送文件权限。

部署助手：[prepare-telegram-api.sh](../tools/prepare-telegram-api.sh)。在服务器保存并执行，助手从 `lsky-pro` 容器的 Compose 标签读取实际服务名，确认它属于 `/opt/lsky/docker-compose.yaml`，生成补充配置 `/opt/lsky/docker-compose.archive.yaml`，不会猜测服务名或启动/重建容器。不是 Compose 部署时会停止，需按实际部署方式配置。

API ID/API Hash 填 `/opt/lsky/telegram-api/.env`（权限600）；API 服务没有对公网发布8081端口。基础镜像是 aiogram 项目维护的 **非官方 Docker 打包** `aiogram/telegram-bot-api:latest`；补充配置用仓库中的轻量Dockerfile将API文件用户调整为与Lsky www-data相同的UID/GID 33，不在VPS编译服务。部署验证后可把基础镜像的实际digest固定为Docker构建参数 `TELEGRAM_API_BASE_IMAGE`。启用 `TELEGRAM_LOCAL=1`。

共享目录 `/opt/lsky/telegram-api/data` 在 API 和 Lsky 中都映射到 `/var/lib/telegram-bot-api`，Lsky只读。使用新建的独立API数据目录，API以UID/GID 33保存文件，图床以www-data读取，包括仅owner可读的下载文件。不要将原有另一个TG服务的目录直接复用，也不要把目录改成777。若自建服务已存在并使用其他UID/GID，需要按实际权限适配；可选 `TG_FILE_GID` 仅用于文件有group读取权限的情况。

助手会从解析后的现有Compose读取并保留图床原有网络，同时读取原项目名，避免更新时换项目创建新数据卷；仍需先验证合并配置。以后更新容器需要同时带上两份 Compose 文件，保留 TG API 网络和共享目录。

独立归档 Bot 如果之前在官方 API 登录过，需要先按 Telegram 官方文档调用一次 `logOut`，然后才能登录本地 API。不要对游客现用 Bot 执行 logOut。Bot Token 留在服务器，不在公共 Worker、仓库或浏览器中配置。

## 2. 图床增加独立 TG 归档策略

后台“存储策略”新增 Telegram，填归档 Bot Token 和频道 Chat ID。API地址 `http://telegram-bot-api:8081`；本地共享目录 `/var/lib/telegram-bot-api`；访问域名填图床域名。无需绑定给游客或管理员上传组，归档命令按指定目标策略使用它。

先更新已构建成功的 Lsky 镜像并部署自建 API，再列出实际策略：

```bash
docker exec lsky-pro php /var/www/html/artisan r2:archive-config --list
```

只输出ID、名称、类型及归档参数，不输出密钥。记录实际 R2 和新 TG 策略 ID。

## 3. 保留旧链接的 Worker

Cloudflare 新建 Worker，上传 [r2-archive-worker.mjs](../tools/r2-archive-worker.mjs)。添加R2绑定 `BUCKET` 指向原R2桶；变量 `APP_ORIGIN=https://dayv.cc`、`SOURCE_STRATEGY_ID=实际R2策略ID`。在同一Cloudflare账号/域名区域添加 **Worker Route** `r2.dayv.cc/*`，保留原有R2自定义域名；不是删除R2域名再重新解析。Worker配置没有任何TG/R2密钥。

验证原有图片能访问，并访问 `https://r2.dayv.cc/.__lsky_archive_probe`，应返回 `lsky_r2_archive:1` 和对应 source ID。启用归档命令还会选一张现有R2图片，对比Worker绑定返回的ETag/大小与R2 API结果，防止绑定了错误的桶；未通过不会启用。空桶请先上传一张小图。

Worker必须一直保留；已有R2直链归档后会依赖它。对不存在的旧路径返回404，不跳转到登录页或HTML错误页。TG服务不可用返回503，源文件尚未删除时仍优先读R2。

## 4. 启用与验证

把下列示例里的 `R2_ID`、`TG_ID` 替换成上一步实际数字，不能原样执行：

```bash
docker exec -u www-data lsky-pro php /var/www/html/artisan r2:archive-config --source=R2_ID --target=TG_ID --links-ready --enable
docker exec -u www-data lsky-pro php /var/www/html/artisan r2:archive --dry-run
```

启用前先完成或取消所有正在进行的R2直传，暂停其他上传再配置，避免旧请求绕过新的容量预留。配置保存在数据库，默认9.5GB触发、全部图床图片归档后停止（`--retain=0`）。已有策略需重新配置；也可指定非零 `--retain` 保留容量；自动定时循环随容器启动。

日常排查：`r2:archive --dry-run` 看实际容量/预留。手动运行 `r2:archive` 与后台使用同一锁，不会并发重复迁移。查看数据库 `r2_archive_jobs` 的phase/error定位未完成任务；不要手工改成done或删掉归档记录，否则旧链接映射丢失。

停用：`r2:archive-config --source=实际R2_ID --disable`。停止新的自动归档，但不能删除 Worker、归档 TG 策略或旧映射。恢复原策略需先验证并复制回原图，不能仅改 strategy_id。

## 备份和验证范围

原有SQLite在线备份包含归档策略、迁移状态和telegram_files映射；继续每日备份数据库、.env。额外备份两份Compose、Telegram API的.env（私有）、Worker代码/变量；Bot API本地下载缓存可重新获取，不是Telegram云端唯一原图副本。

自动测试使用Laravel/SQLite和模拟R2/TG传输，覆盖容量预留、失败不删源、删除重试、哈希不一致、350MiB完整流式迁移、旧路径映射、Range和本地目录边界。真实Telegram服务、权限、磁盘、Worker路由与速度需要在服务器验证；代码提交不等于已部署启用。

依据：
- https://core.telegram.org/bots/api#using-a-local-bot-api-server
- https://github.com/aiogram/telegram-bot-api
- https://developers.cloudflare.com/r2/api/workers/workers-api-reference/
- https://developers.cloudflare.com/workers/configuration/routing/routes/
