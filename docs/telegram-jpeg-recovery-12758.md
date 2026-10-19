# 12758–12766 照片组定向重试

2026-10-19 源组 `1109579085:g13352363222278213` 返回 HTTP/TG400 `IMAGE_PROCESS_FAILED:item7`。九张均为960×1280且本地ImageMagick能解码；尚不能确定是TG临时处理失败还是JPEG兼容性。用户已核对目标频道九张均未发送。此步骤只适用于本次事件，不用于自动清理其他未确认上传。

1. 更新本分支。保持 `tg-import-oldest-fast` 停止，保留前一步的 `/opt/tg-import/state/diagnostic-12758` 原图。
2. `python3 /opt/tg-import/source/tools/tg-channel-import/prepare-jpeg-12758.py`：用现有lsky-pro容器ImageMagick转为sRGB标准JPEG，quality95、无交错、去元数据。尺寸不变但再次有损压缩。输出 `/opt/tg-import/state/normalized-12758`，原图不改。目录已存在时停止而不覆盖；成功后创建原始及重编码SHA256清单，供试传核对。脚本不发送TG。
3. `python3 /opt/tg-import/source/tools/tg-channel-import/recover-unsent-12758.py --apply`：严格要求当前唯一待确认组是12758–12766、complete-v2/offset12753、完整组索引匹配、上一组记录存在、目标九张还未入库、服务端sending无message_ids/result。先备份采集SQLite及服务端登记，归档旧sending登记，只移除九条uploading意图。保持游标、统计、会话、已完成记录和生产数据库不变。
4. 试一帖（无需构建采集镜像）：

```bash
docker compose -p tg-import -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml run --rm --no-deps -T -v /opt/tg-import/source/tools/tg-channel-import/retry-jpeg-12758.py:/tmp/retry-jpeg.py:ro --entrypoint python collector /tmp/retry-jpeg.py
```

包装器只在这一临时进程中替换源下载，校验消息ID、源频道、组ID和两份文件SHA256，从准备好的JPEG复制到导入器临时目录。原导入器重新计算上传哈希并走正常图床API、TG映射和相册保存流程；最多处理一帖，其他消息拒绝处理。它不修改后台容器/镜像代码，不自动重试发送。

成功应出现九条imported、paused_at_post_limit。核对后 `docker start tg-import-oldest-fast` 恢复正常搬运。失败时保留状态并读取新的API错误，不能重复执行清理。不要同时运行两个采集器。

本次流程没有在VPS远程执行；需要操作者在SJC运行并检查输出。
