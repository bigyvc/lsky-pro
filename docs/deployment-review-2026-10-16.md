# 2026-10-16 新站部署复盘与代码审查

范围：Debian13 新站、统一 Compose、Caddy、游客 TG、管理员 R2、Worker 旧链接接续、9.5GB 全部归档、缓存清理、GitHub 私有备份。未登录生产服务器，也未使用真实 Bot 或 R2 密钥；以下代码回归使用本地 Laravel/SQLite 与模拟远程服务。

## 今天已修复的问题与本轮补充

| 问题 | 根因与修复 | 验证 |
|---|---|---|
| TG 上传后503，记录和映射不一致 | 原模型把 `"0"` 当空目录；已显式区分 null/空串与零字符串 | 实际 Image::filesystem 读取 `0/photo.png` |
| 直接把目录规则设为0仍可能失败 | 上传保存处还有真假判断；本轮改为从标准化的最终路径拆目录 | 目录规则 `0`、空规则、`0//` 均经过实际上传与映射检查 |
| 数据库中有重复图片但映射缺失 | 仅靠哈希命中就复制旧记录，不再发 TG | 命中记录后核查 TG 映射，缺失时本次文件重新发送并补回 |
| 同名不同内容的 TG 图片互相覆盖 | 适配器按 path 更新映射，应用未分配空闲名称 | 原名优先、冲突加6位码；固定256个锁分片协调名称分配，回归验证旧图字节未改变 |
| sendDocument成功、映射保存失败 | 远端成功不等于本地持久化成功 | 明确返回失败；尽力清理本次消息，不主动清理旧消息；模拟数据库保存异常 |
| 上传返回的文件大小不符 | 远端回包不应直接采信 | 拒绝保存映射，并清理本次消息 |
| TG复制大图占大量PHP内存 | copy 调用 read() 生成完整字符串 | 改 readStream/writeStream；测试禁用 read()，复制仍成功 |
| move/copy到自身导致记录删除 | move 原来复制后无条件 delete | 相同路径直接返回；映射仍在 |
| 相机JPG完成失败 | 64KiB头不足以覆盖大EXIF/ICC段 | JPEG有界扩展到4MiB；仍检查真实格式，不下载整图解码 |
| 上传页完成回调报组件未初始化 | blueimp替换并清理旧input，回调仍引用旧input | 挂到稳定容器，真实blueimp/jsdom验证多次选文件与失败回调 |
| 设置角色并发0仍一张张传 | 分片适配器把文件强制串行 | 文件调度读取角色限制；0无限应用层上限，正整数限制活动文件数 |
| 5张小图初始化时被大文件任务上限拦住 | 统一分片后小图也被计入3个大文件名额 | 小图单独计数，保留总任务/预留限制；R2及游客通道均测试5张小图 |
| 刷新、丢响应后返回错误记录 | 完成结果需要幂等，不能仅按路径视为同一次文件 | 已有R2记录必须与哈希和长度匹配才复用；原状态机和缓存结果测试仍保留 |
| GitHub备份可能打包遗留SQLite | 仅判断默认路径文件存在，不保证应用正在用它 | 读取运行容器实际连接驱动和路径；非默认SQLite停止并提示，不伪报成功 |
| 测试失败可能显示错误却退出0 | Laravel默认异常处理与独立CLI测试退出码不一致 | chunk-controller与本轮TG回归显式异常退出1，CI纳入真实镜像TG回归 |

名称锁仅协调共用本机 storage 的 Lsky 进程，不提供跨服务器/外部写入的分布式保证。相同Bot被配置成多个独立策略时，映射仍按策略隔离，不保证跨策略内容去重。复用文件不证明频道文件永久存在；本轮只对已知本地映射缺失进行补传，真实远端失败仍应排查，不自动猜 file_id。

## 文档与构建核对

- 主 README 改为新机安装顺序；旧独立部署与功能说明移至 project-reference.md，并明确不要替换生产目录。
- 新站策略 TG=2/R2=3 与旧站 TG=4 区分；其他实例必须先 --list。
- TG API 地址和共享目录以醒目字段列出；网络连通、目录可读、映射存在、原图可打开为四个检查点。
- R2 凭据、CORS、角色KB单位、原文件名、文件并发/分片并发分别说明。
- Worker探针不是最终桶验证；--links-ready还会比对真实对象ETag/大小。
- dry-run不迁移，不能宣称已完成真实归档；旧链接依赖长期保存映射和Worker。
- 备份先手动再timer，私有仓库dayv-io分支，不合并PR；清楚标注未加密、单快照、PAT期限及恢复边界。
- Dockerfile中ImageService显式md5随源码更新；热补丁manifest内容指纹同步，避免再次出现“改了文件却漏改校验值”。

## 回归入口

CI 中的真实 amd64 镜像运行：

```bash
docker cp test/php/telegram-upload.php lsky-smoke:/tmp/telegram-upload.php
docker exec -e LSKY_TEST_APP_PATH=/var/www/lsky lsky-smoke php -d memory_limit=64M /tmp/telegram-upload.php
```

本地已有PHP及依赖时可执行：

```bash
php test/php/telegram-upload.php
php test/php/chunk-controller.php
php -d memory_limit=16M test/php/chunk-store.php
php test/php/r2-direct-controller.php
php -d memory_limit=64M test/php/r2-archive.php
cd test && npm test
```

还需核对 Dockerfile md5、shell语法、Compose YAML、README相对文件链接及部署清单指纹。Docker镜像构建、启动与升级验证由GitHub Actions完成，本地未运行Docker，不能把本地测试通过表述为镜像构建成功。

## 仍需生产实测

本次修复不会自动更新VPS。待目标提交Actions通过后按README备份并更新，强制刷新上传页。

1. 无痕游客TG上传和原图打开；管理员R2小图、原相机JPG、同名不同内容及多图并发。
2. 一张小图的真实归档、TG校验、R2释放和原URL继续访问。当前9.5GB配置与dry-run成功并未覆盖这一步。
3. 独立目录恢复演练；备份不包含图片对象与TG API状态，也不能保证快照之后发生的迁移仍与快照一致。
4. 自动清理服务已运行，但registered=0尚未证明实际释放缓存；用户当前选择暂不排查，不调整线上清理规则。

不要为修复映射问题批量重写历史数据库、把aborted改recorded、关闭格式/CSRF校验、公开Bot API端口，或删除数据卷。
