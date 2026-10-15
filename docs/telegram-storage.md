# Telegram 存储驱动使用说明

通过 Bot API 的 `sendDocument` 将图片原文件存入自己的频道，不使用会压缩图片的 `sendPhoto`。`telegram_files` 表保存策略ID、逻辑路径、file_id、message_id和大小；数据库备份不可缺少。

## 官方 API 与自建 API

| 模式 | 官方限制与本项目适配 |
|---|---|
| 官方 `https://api.telegram.org` | multipart上传支持50MB，但getFile下载上限20MB。用作图床时把单图限制设到20MB以内，否则可能上传成功却无法读取。 |
| 自建 Bot API，local模式 | Telegram支持上传2000MB、下载无20MB限制；本项目归档仍限定512MiB。配置API地址和相同绝对路径的本地共享目录，图床以只读方式挂载。 |

当前版本自动通过图床输出 Telegram 图片，链接是 `https://图床域名/{key}.{扩展名}`，无需修改上传组的原图保护开关。服务端保管Bot Token，不能给访客返回 `https://api.telegram.org/file/bot<TOKEN>/...` 这种真实下载URL。

读取使用getFile获取路径，缓存50分钟。官方API通过HTTP流式读取；自建API的绝对路径只允许位于配置的 `local_root` 内（校验realpath，拒绝目录越界）。输出原图不进行大图解码，支持单段Range。

## 配置

1. 用BotFather创建Bot，建立自己的频道，把Bot设为频道管理员并授予发送文件权限。
2. 在后台新建Telegram存储策略，填写Bot Token、频道Chat ID（通常以 `-100` 开头）和图床访问域名。
3. 官方API模式的Bot API地址可留空，本地共享目录留空。
4. 自建模式填内网API地址，例如 `http://telegram-bot-api:8081`，共享目录 `/var/lib/telegram-bot-api`。两个容器应使用相同绝对路径，图床只读，www-data必须有读取权限。
5. 普通上传将策略绑定到相应角色组；专用于R2归档的独立策略无需绑定上传组。

自建R2归档的部署、Worker转发和9.5GB阈值配置见 [归档说明](r2-archive-telegram.md)。可用独立归档 Bot，也可复用现有游客 Bot 与频道。复用时保留原策略 ID，同一 Bot 的全部策略一起切换；先暂停上传并调用官方 logOut，再验证自建接口并更新 api_base/local_root。API ID/API Hash 填在自建服务的 .env，不填图床策略。完整命令及验证见归档手册。

## 保存、删除与访问

上传成功后再替换映射，失败时旧映射仍保留。删除频道消息受Telegram权限及API限制，某些旧消息可能无法删除；图床记录的删除不能保证频道历史消息全部消失。请勿删除仍被图片使用的策略、Bot或频道。

R2原有公共域名链接不会因修改数据库而自动转到TG。保持旧R2链接需要归档Worker；新版本配置归档时会验证Worker和桶绑定后才允许启用。不要仅手工修改 `strategy_id` 后就删除R2对象。

备份数据库、.env、独立TG策略配置、Compose和API凭证。自建Bot API下载缓存会占用VPS磁盘，归档会检查磁盘余量；实际读取速度取决于TG和服务器网络，不等同于R2直连。

## 排查

- 上传失败：核对Bot、频道ID、管理员权限和API连通性。
- 官方API已上传但打不开：检查是否超过20MB下载上限。
- 自建API读取失败：核对local模式、共享路径、文件UID/权限和目录边界；不要放开整个目录为777。
- 503归档读取失败：检查自建API是否运行及本地文件是否可读；先解决读取再执行迁移清理。
- 归档状态在数据库 `r2_archive_jobs` 中；仅当目标文件长度和MD5/SHA1通过验证后才切换记录并删除R2源文件。

依据：https://core.telegram.org/bots/api#senddocument 、https://core.telegram.org/bots/api#getfile 、https://core.telegram.org/bots/api#using-a-local-bot-api-server

## 游客目录 0 导致图片 503 的修复

早期版本将目录字符串 `"0"` 误判为空，导致 TG 映射为 `0/图片.png`，网页却读取 `图片.png`。修复后的 Image::pathname 显式区分 null/空串与 `"0"`。更新镜像后原图片链接可恢复，不需要删除 TG 文件、改数据库映射或重传。此问题与原图保护开关无关。


### 目录规则、重复上传与重名保护（2026-10-16）

上传保存路径也显式保留 `"0"`，并使用与 Flysystem 相同的路径标准化，避免空规则、重复斜杠导致图片记录与 TG 映射不一致。更新后不需要批量修改历史数据库。

重复上传仅在 TG 映射存在时复用原路径；已知映射缺失时，使用本次上传的文件补回映射。同名但内容不同的图片优先保留原名，冲突后追加6位十六进制编码；名称分配和写入持锁，不覆盖原图。映射保存失败会明确失败，并尽力清理本次新发出的消息，保留旧映射。

TG 复制改为流式读写，不把整张大图载入 PHP 字符串；移动/复制到自身不删除映射。以上不代表能恢复已丢失的 Bot Token、数据库或历史 file_id：仍须备份原数据库和 APP_KEY，不能通过换 Bot 读取旧文件。
