# TG发送失败诊断与12330～12334定向重试

旧适配器使用Guzzle默认HTTP错误行为，HTTP 400/403/429/5xx与连接超时均被捕获为同一句“检查网络、服务状态与权限”。仅凭这句话，或Bot API容器没有匹配日志，不能还原历史失败原因，也不能断定请求没有发送。

新版不会自动重试上传。它只记录固定、脱敏的诊断字段：方法名、HTTP状态、Telegram error_code、cURL errno、受控错误分类和整数retry_after。不记录原始description、回包、请求URL、Token或底层异常链。照片项错误若可识别，会保留item编号；成功结果不变。另修复deleteMessage成功返回布尔true与原array返回类型冲突的问题。

示例（示意，不代表此次已确认限流）：

```text
Telegram Bot API请求失败 [sendMediaGroup]：HTTP=429 TG=429 curl=0 category=RATE_LIMIT retry_after=37s
Telegram Bot API请求失败 [sendMediaGroup]：HTTP=400 TG=400 curl=0 category=PHOTO_INVALID_DIMENSIONS:item3
Telegram Bot API请求失败 [sendMediaGroup]：HTTP=0 TG=0 curl=28 category=TIMEOUT
```

## SJC当前故障的恢复范围

已确认2026-10-19 15:38的待确认组是源频道1109579085的 `g13341818998531757`，成员12330～12334。用户已人工核对目标频道没有这五张。此确认是定向重试的前提；不能将此脚本套用于其他Sending登记，不能当作定时自动清理器。

### 1. 安装安全诊断补丁

在采集器已停止时，更新 `/opt/tg-import/source` 到包含本修复的提交，执行：

```bash
python3 /opt/tg-import/source/tools/tg-channel-import/install-api-diagnostics.py
```

脚本只替换 `/opt/lsky/data/app/Filesystem/TelegramAdapter.php`，先核对原文件MD5为已审查版本 `28996cb81484fcc29f34ecafb1d1ac4b`，取得采集器和服务端用户导入锁，备份原文件，在容器内做PHP语法检查，再原子替换并平滑重载Apache。原文件备份于 `/opt/tg-import/api-diagnostics-backup-*`。版本不匹配会拒绝覆盖，需先核对本地改动。安装同一文件可重复执行，不重新安装图床、不修改TG凭据。

这是运行中数据卷内的临时热修复；源码同时进入GitHub，后续更新要使用包含本修复的镜像。更新回旧镜像可能覆盖它。

### 2. 预览并仅解除本组待确认状态

```bash
python3 /opt/tg-import/source/tools/tg-channel-import/recover-unsent-12330.py
python3 /opt/tg-import/source/tools/tg-channel-import/recover-unsent-12330.py --apply
```

脚本要求：唯一待确认集合恰为这五张；完整源组索引匹配；旧游标恰为complete-v2/12325；上一组已成功；服务端登记仍为sending且没有message_ids/result；数据库中既没有这五个预留编码，也没有该账号相册下对应的新文件名。任一条件改变就停止。

执行前备份采集SQLite及Sending JSON到 `/opt/tg-import/recovery-unsent-12330-*`，将旧Sending登记改名保留审计，仅移除这五条本地uploading意图。**游标、统计、完整源索引、已完成记录、TG会话和图床生产数据库均不改，不删除TG消息或媒体。**

### 3. 只试一帖

当前采集镜像已有交错组修复，本次采集代码未改变，不需要重建采集器或拉起新版图床镜像。先让原后台任务保持停止，用同一私有env/状态运行一帖：

```bash
docker compose -p tg-import -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml run --rm --no-deps collector import-history --order oldest --until 22569 --max-posts 1 --download-kib 1024 --interval 10
```

成功时应看到12330～12334的五条imported及paused_at_post_limit，然后才能恢复原后台任务。若仍是HTTP500，读取Laravel最新错误中的新诊断字段，不反复清理状态重发。原请求结果不确定时仍需人工核对。

## 根据诊断处理

- HTTP/TG429：按retry_after等待，再安排恢复；不能由旧泛化日志认定429，也不能保证固定间隔永不触发限流。
- HTTP400及照片分类：核对该项真实JPEG内容、尺寸或TG处理限制，不静默改文档或跳过。
- HTTP401/403：检查Bot认证、频道权限；不要把Token粘贴到日志或聊天。
- curl28：连接/请求超时，发送结果仍可能不确定，核对目标频道后再决定恢复。
- 其他5xx/无JSON：检查本地API服务、代理和资源；诊断不会输出原始HTML或请求地址。

官方参考：[Bot API ResponseParameters](https://core.telegram.org/bots/api#responseparameters)、[Bots FAQ限流说明](https://core.telegram.org/bots/faq#my-bot-is-hitting-limits-how-do-i-avoid-this)。
