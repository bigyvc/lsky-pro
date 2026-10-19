# 照片组429限流与恢复

本次SJC故障对应2026-10-19 23:21:07（Asia/Shanghai），源组 `1109579085:g13519145014814085`，15395–15398，HTTP429/TG429/curl0/RATE_LIMIT，retry_after18秒。旧适配器在明确被拒绝后仍留下sending登记，采集器留下uploading意图；保守监控因此阻断自动重启。

## 后续明确429的自动处理

适配器仅在收到 `HTTP429`、JSON `ok=false`、整数 `error_code=429`、无传输错误，且正整数retry_after时，抛受控TelegramRateLimitException。照片组发送捕获此异常：retry_after在1–60秒时，等待指定秒数加2秒，再重试，最多额外2次。每次使用新的本地文件句柄，保持调用方原始tmpfile打开，避免HTTP multipart释放流导致重试发送空文件。成功后沿用正常映射/相册保存；发送内容不重新编码。

400照片处理错误、403、HTTP5xx、无有效JSON、超时、网络错误、长于60秒的限流及连续3次拒绝，仍停止并保留待确认状态。不能称为“任何失败自动解决”。监控本身不删除任何pending/sending；短时明确429在原请求内自动等待后恢复。

## 本次历史状态修复

先暂停watchdog（创建 `/opt/tg-import/watchdog/paused` 并停止其service），更新分支。在采集容器已停止时：

```bash
python3 /opt/tg-import/source/tools/tg-channel-import/install-rate-limit-retry.py
python3 /opt/tg-import/source/tools/tg-channel-import/recover-rate-limit-15395.py --apply
docker compose -p tg-import -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml run --rm --no-deps collector import-history --order oldest --until 22569 --max-posts 1 --download-kib 1024 --interval 10
```

安装器核对原诊断版适配器MD5 d9db4b9ab5b8656d9d2f042f4ae156d6；备份并部署Adapter及新异常类，容器内核验PHP语法，平滑重载Apache。源码及部署清单也更新，后续使用包含修复的镜像，避免旧镜像覆盖热修复。

本组恢复器有固定范围和证据门槛：准确的日志时间/429错误、sending文件mtime与错误时间接近、等待期已过、预留编码准确、唯一pending为四张、完整源组索引、上一帖完成、生产数据库没有预留编码/同名目标记录、未记录发送结果。备份采集SQLite和服务端登记后，归档本组sending，仅解除四条uploading意图。游标、统计、会话、已完成记录、生产数据库、媒体均不改。任何证据变化就停止；不能套用于其他未确认请求。

四张imported并paused_at_post_limit后，执行：

```bash
bash /opt/tg-import/source/tools/tg-channel-import/resume-paced-15395.sh
```

脚本核对四张均done且游标15395，保留并重命名原停止容器，按同Compose配置创建新的tg-import-oldest-fast，将帖子间隔从3秒提高到10秒（1024KiB/s下载、120秒下载超时、最多3次下载尝试保持原值），再恢复监控。10秒间隔降低频率，但不能保证永远不限流。不要同时运行旧容器，也不使用--remove-orphans。

验证覆盖429后重试携带完整文件、原流保持可用、拒绝网络/图片错误重试、次数上限，及本组恢复证据不匹配时阻断。VPS实际恢复仍需运行上述命令查看结果。
