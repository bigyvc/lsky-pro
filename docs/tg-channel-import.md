# 可选：Telegram频道照片组导入

试验分支`test/tg-channel-import`。采集器是独立Docker服务；图床需要同一分支的照片组接口版本。不会自动改动VPS或master。

## 当前行为

- 按源帖grouped_id组织照片，组内按消息ID升序，1张调用sendPhoto，2至10张调用sendMediaGroup，目标频道显示照片拼图；不是把几张图合成一张。
- 每张在图床中有独立记录、独立URL，明确进入当前账号的“频道图片”相册；文件标签是`mzt-22569.jpg`。TG照片预览通常不显示文件名。
- 当前路径为下载源频道照片，再整组上传为TG照片。**不保证TG保存的JPEG与源下载字节完全相同**；不会把“照片组”描述成原件无损。服务端读取TG实际保存的最大照片，确认JPEG/长度，再更新大小、尺寸、MD5和SHA1。回包同时保留来源哈希，采集器校验来源顺序及相册。
- 默认最近200条消息中选最多20张；不会为凑满数量拆开一个原帖。如果最后一组放不下则停止，实际可能少于20张。扫描窗口截断的最老一组保守跳过。
- 仅采集TG照片。混有视频、文档或受保护消息的原帖整组跳过，不悄悄丢掉成员；不跟随外链。照片默认单张最多8MiB，最多10张/组，组请求最多约80MiB；超过TG照片尺寸限制时停止，不降级为单张文档。
- 相同源帖重复导入会跳过或使用服务端幂等结果；不同原帖即使图片内容相同也保留各自组的成员，不以跨帖内容去重破坏组合。普通图床上传的去重逻辑不变。
- 已经按旧版单张导入的记录不自动重发、改名或删频道消息。源帖只有部分成员已导入时停止供人工核对；不会补成残缺照片组。
- 无实时同步、全历史翻页、编辑/删帖跟随或公开图库页面。此次只做近期照片组试导入。

## 1. 图床准备

可使用管理员或专用用户。站点启用API；当前角色绑定实际Telegram策略并允许JPG/JPEG。质量100、不转换格式、关闭水印和图片审查，服务端仍执行角色大小、用户容量和相册归属校验。

在该账号创建“频道图片”相册，不需要设为默认。通过账号API或VPS生成专用上传Token，只写入私有env。工具在导入前检查profile的`upload_album_id_supported`和`telegram_photo_groups_supported`；旧镜像会在下载/上传前被拒绝。

默认按名称解析Token账号自己的相册，重名时要求额外指定ID；不存在或无权限时停止。普通上传不传album_id时继续使用原默认相册，账号默认相册不被修改。

## 2. SJC首次安装

已有目录不要覆盖。API ID/Hash可复用，用户登录会话与现有Bot API完全分开。

```bash
install -d -m 700 /opt/tg-import
git clone --branch test/tg-channel-import --single-branch \
  https://github.com/bigyvc/lsky-pro.git /opt/tg-import/source
install -d -o 10001 -g 10001 -m 700 /opt/tg-import/state
install -m 600 /opt/tg-import/source/tools/tg-channel-import/import.env.example \
  /opt/tg-import/import.env
nano /opt/tg-import/import.env
```

仅在服务器填写，不发送到聊天：

```ini
TG_API_ID=你的API_ID
TG_API_HASH=你的API_HASH
SOURCE_CHANNEL=botmzt
LSKY_URL=https://dayv.io
LSKY_TOKEN=账号原始Token，不加Bearer前缀
LSKY_STRATEGY_ID=该账号绑定的TG策略ID
LSKY_ALBUM_NAME=频道图片
LSKY_ALBUM_ID=0
IMPORT_AUTHORIZED=no
```

LSKY_ALBUM_ID=0表示按名称解析，不表示无相册；重名时改为你选定相册的正整数ID。现有SJC的TG策略是2，但其他部署先核对，不能照抄。

```bash
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml config --quiet
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml build
```

无公网端口、无常驻任务。运行内存上限512MiB/1核，采集状态单独存放，不挂载图床或Bot API数据卷。

## 3. 登录和只读预览

```bash
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml \
  run --rm -it collector login
```

在终端输入用户手机号、验证码及两步验证密码，不是Bot Token。之后预览不会下载照片或请求图床：

```bash
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml \
  run --rm collector preview --limit 20 --scan 200
```

每行显示source_group和有序消息ID列表；末尾selected是图片候选数，posts是源帖数。账号须有频道访问权，频道开启内容保护则停止。不自动加入频道，也不绕过保护。公开可见不等于允许转载。

## 4. 整组导入

确认有使用与展示授权、图床和采集器均更新后，将私有env的IMPORT_AUTHORIZED改为yes，再运行：

```bash
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml \
  run --rm collector import --limit 20 --scan 200
```

一次处理一个源帖，成功后间隔3秒；下载暂存后整组提交。服务端复用既有ImageService执行权限、格式与容量检查；本次组使用独立随机存储目录，不覆盖历史文件或旧组映射。数据库记录在TG回包及实际照片读取验证后提交。

抽查：TG频道照片拼图/顺序、“频道图片”相册归属、每张URL实际打开、图床大小/尺寸与TG下载一致；其他普通上传仍进原默认相册。TG预览照片可能调整原字节，不要用源文件哈希断言TG照片完全相同。

## 5. 状态、失败和保留范围

```bash
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml \
  run --rm collector status
```

- 采集端在POST前持久化uploading。结果不确定时停止，不能删除状态库盲目重试。
- 服务端按账号、TG策略、相册及源帖持久化幂等状态。TG发送前记录sending；完成的源帖可直接返回原结果，未完成/不确定的源帖不自动发送第二次。
- TG明确返回新消息ID但副本验证失败时回滚本组图床记录，并尽力删除这些已知新消息；不删除原频道帖子、旧文件或未知消息。发送丢失回包时可能留下未知孤立媒体组，需要人工核对。
- 若数据库已提交而状态文件最后写入失败，记录可能存在，仍需人工核对；不把错误自动当作重传许可。
- 会话、采集状态位于`/opt/tg-import/state`；服务端组状态位于图床卷`storage/app/tg-photo-import`，需单独私密备份。现有SQLite备份包含图床记录/映射，不代表包含这两个目录。
- 临时目录成功或异常后清理，强制中断残留的download-*在下次取得独占锁后清理；至少保留2GiB磁盘。不能删除Bot API的TDLib状态或整盘缓存。

## 6. 更新与验证

先等待目标提交的两条Actions成功。更新Lsky到对应sha镜像时保留SJC原来的完整Compose参数及覆盖文件；不改Bot API，不删除卷。采集工具单独git pull与build：

```bash
git -C /opt/tg-import/source pull --ff-only origin test/tg-channel-import
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml build
```

保留私有import.env、登录会话和状态，已登录账号无需重新登录。工具使用现有Token，明确相册设置后再重新预览。

本地：`python3 test/tg-channel-import.test.py`，`php test/php/telegram-photo-import.php`，以及原TG/分片/R2回归。CI分别构建独立采集镜像和Lsky镜像，覆盖整组顺序、不拆帖、照片实际字节、相册归属、失败回滚和丢回包不重发。替身测试不能替代SJC真实账号的照片组导入验收。
