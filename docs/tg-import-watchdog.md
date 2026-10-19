# Telegram 搬运监控与有限自动恢复

本监控部署于SJC宿主机，管理已存在容器 `tg-import-oldest-fast`；不是ChatGPT定时任务，不需要图床/采集器镜像重新构建。不安装额外依赖，不读取私有import.env，不更改Bot/API认证。

## 已发生问题与处理边界

| 问题 | 监控行为 |
| --- | --- |
| 下载文件长时间无变化，任务无进展，无uploading意图 | 15分钟后优雅停止，重新读取SQLite，再安全启动原容器 |
| 异常退出码1，任务未完成，没有uploading意图 | 自动启动，读取原有断点 |
| 旧版本将交错照片组拆开 | 已由complete-v2索引修复；监控不重写历史组、游标或数据库 |
| Telegram IMAGE_PROCESS_FAILED | 记录脱敏分类和待确认消息，保留现场；不擅自重编码/重发 |
| HTTP500、超时且存在uploading意图 | 阻断自动重启，不清理sending登记、不重复发送 |
| 活跃上传尚未完成 | 15分钟内记为uploading，不当作异常 |
| 运行30分钟无进展且没有下载文件 | stalled_unknown，只记录待检查，不杀进程 |
| 正常完成、正常退出、手动暂停 | 不恢复 |
| 其他采集进程持有state/.lock | 不启动第二个采集器 |

HTTP500并不说明TG没收到照片。已出现的两次待确认发送均需要人工核对频道；不能通过定时删除uploading/sending实现自动恢复。尤其IMAGE_PROCESS_FAILED时，重新编码涉及原文件变化，既有流程只授权过指定照片组，监控不会扩大到所有图片。

## 安装

更新 `/opt/tg-import/source` 到本分支后：

```bash
bash /opt/tg-import/source/tools/tg-channel-import/install-watchdog.sh
systemctl list-timers tg-import-watchdog.timer --no-pager
```

安装先运行只读检查，核对SQLite和容器/state绑定；随后安装到 `/usr/local/libexec/tg-import-watchdog.py`。每分钟检查，开机2分钟后开始。每次恢复最少间隔5分钟，每小时最多3次（写入监控状态后才调用Docker，即使启动失败也计入预算）。下载停止时容器最多等待30秒；停止后再读uploading意图，避免检查与发送开始之间的竞态。

只读预览不会操作Docker/采集记录，但会更新独立的监控状态文件：

```bash
python3 /usr/local/libexec/tg-import-watchdog.py
```

## 查看结果

```bash
cat /opt/tg-import/watchdog/monitor.json
journalctl -u tg-import-watchdog.service -n 20 --no-pager
```

`restarted`表示已发起启动，不保证后续上传一定成功。下一轮继续核对。`blocked_uncertain`显示待确认的消息ID，`latest_available_diagnostic`是最新可读取的脱敏TG错误，仅作线索，可能来自更早请求。`restart_limit`表示当小时预算已用完。`monitor_error`在journal显示Docker、SQLite或配置检查异常，修复监控环境后再继续。

状态变化记录在 `/opt/tg-import/watchdog/events.jsonl`，安装logrotate规则按大小/周期轮转。这里的“告警”是服务器日志和状态文件，**不会自动向手机或TG发送通知**。没有配置通知Bot凭据，也没有复用图床Bot向频道发管理消息。

## 手动操作前暂停监控

如果要更新、排查或定向恢复，先执行：

```bash
mkdir -p /opt/tg-import/watchdog
touch /opt/tg-import/watchdog/paused
systemctl stop tg-import-watchdog.service
```

随后进行既定备份/更新/修复。单纯停止Docker容器不一定表达暂停意图，因此推荐使用上述暂停标记。恢复监控：

```bash
rm -f /opt/tg-import/watchdog/paused
systemctl start tg-import-watchdog.service
```

这会恢复监控；若采集器是正常退出/手动停止状态，仍需按原流程明确 `docker start tg-import-oldest-fast`。不通过清理监控文件绕过待确认上传。

彻底停用：

```bash
systemctl disable --now tg-import-watchdog.timer
systemctl stop tg-import-watchdog.service
```

本地验证：策略测试覆盖异常退出、下载卡住、正常上传、冷却/预算、完成/手动退出、外部锁、脱敏以及停止时新增上传意图的竞态。Docker/systemd实际部署需在VPS执行安装命令后验证。
