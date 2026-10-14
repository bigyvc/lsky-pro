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

自建R2归档的部署、Worker转发和9.5GB阈值配置见 [归档说明](r2-archive-telegram.md)。建议使用独立归档Bot，避免切换游客现用Bot的API会话。已经在官方API登录过的Bot，迁到自建服务前需按Telegram文档调用logOut。

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
