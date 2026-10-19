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
- 支持近期试导入，以及固定快照的全历史分页、逐帖断点续传。无实时同步、编辑/删帖跟随或公开图库页面。

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

一次处理一个源帖，默认成功后间隔30秒，可用--interval调整（至少3秒）；下载暂存后整组提交。服务端复用既有ImageService执行权限、格式与容量检查；本次组使用独立随机存储目录，不覆盖历史文件或旧组映射。数据库记录在TG回包及实际照片读取验证后提交。

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


## 7. 全频道历史统计与搬运

先更新采集器。已通过照片组验收的图床镜像`sha-8d500dffae05836bd595b40422ded6d04595c1ba`支持本节接口；此次历史功能只需更新采集器，不必再次替换图床容器。仅搬运你有权使用的频道照片。

```bash
git -C /opt/tg-import/source pull --ff-only origin test/tg-channel-import
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml build
```

### 7.1 先只读统计真实数量

```bash
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml \
  run --rm collector plan
```

`plan`仅分页读取消息元数据，不下载照片、不调用图床，不创建历史导入任务。末行`mode=completed`才是完整统计；运行中每100帖或约10秒输出一次进度（以读到下一帖为输出时机）。

- `snapshot`：本次统计时最新消息ID。之后新增消息不计入本次统计。
- `stats.eligible_photos`：按消息类型、保护标志与大小筛选的候选数，实际JPEG格式、尺寸和角色权限仍在上传时检查；`source_bytes`是这些源照片的字节总和，不是已消耗流量。
- `skipped_photos`：受保护、混合媒体或超限原帖中跳过的照片数；`skip_posts`按原因统计整帖数量。文字、服务消息也会作为非照片帖扫描，不算可搬图片。
- `estimated_network_bytes.local_api_path`：源照片总大小约3倍（源下载、Bot上传、实际照片校验）。`public_api_roundtrip`约5倍，额外计算采集器通过公网图床入口上传带来的出入站。TLS、请求开销、照片处理后的字节变化及重试均未计入，不能作为账单读数。以VPS供应商实际计费方式核对，另留余量。

### 7.2 小批试跑并验证续传

```bash
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml \
  run --rm collector import-history --max-posts 20 --download-kib 1024 --interval 30
```

默认从最老往最新搬运（--order oldest），可显式选择--order newest。照片组不能按相邻消息判断：源频道可能出现A1、B1、A2、B2交错排列。首次运行新版会扫描完整频道消息元数据，将消息ID与grouped_id索引到采集SQLite（不下载图片），每500条报告进度；索引完整后才允许上传。后续批量获取最多100条消息对象，按完整grouped_id组合，不将全部消息对象放进内存。索引中断后重新建立，绝不提交半组。快照之后同组还有成员时拒绝截断，不发送半组。

第一次启动固定最新消息ID；后来新增帖子不加入任务。已有快照的索引后续复用，每次获取原帖时仍检查源消息存在、grouped_id一致。历史源帖发生删除/变更时会停下来，不以残缺内容继续。短窗口preview只做预览，实际import与import-history均使用完整元数据索引。

`--max-posts 20`在本次处理20个原帖后暂停，包括已完成跳过和非照片帖子；不会拆组。以前最近20张的已成功记录会复用，不重新下载或重发。结果不确定、旧版仅导入半组的记录会停止任务，不能删库强行重试。

### 7.3 后台继续全量搬运

确认试跑正常，再去掉单次帖数限制。首次创建后台任务：

```bash
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml \
  run -d --name tg-import-history collector import-history --download-kib 1024 --interval 30

docker logs --tail 30 -f tg-import-history
```

退出日志跟踪用Ctrl+C，不会停止后台容器。`--download-kib 1024`限制源照片下载的平均速度约1MiB/s，允许一个TG下载块的短时突发；不是整台VPS的带宽限制，也不限制图床→Bot API内部发送的瞬时速度。`--interval 30`每个新导入原帖成功后等待30秒，降低持续请求频率，不能保证绝不触发TG限流。按实际平均每帖张数估时，例如每帖5张、2万张约4000帖，单等待约33小时，另加传输与校验时间。

容器不自动重启。正常完成后退出；断电、网络错误、磁盘不足或结果不确定也会退出，需先看日志。Telegram短限流最多60秒由客户端等待；更长限流打印要求等待的秒数并退出，等待后再继续。不会自动重试可能已经成功的上传POST。

同名容器已经存在时，不要重复执行上面的创建命令：

```bash
docker logs --tail 50 tg-import-history
docker inspect tg-import-history --format '状态={{.State.Status}} 退出码={{.State.ExitCode}}'
# 确认已停止、没有不确定上传且故障已排除后，使用原容器和原参数继续：
docker start tg-import-history
```

不需要重登TG或重新生成Token。数据库里已完成的历史任务再次启动会显示`already_completed`，不会把新消息偷偷并入旧快照；后续新帖可用近期导入命令处理。任务不支持自动跟随频道编辑、删除或后来补发的原帖成员。

### 7.4 进度、检查点与停止

搬运期间可另开终端只读查看状态，不抢占采集锁、不清理正在下载的文件：

```bash
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml \
  run --rm collector status
```

`group_cursor=complete-v2` 表示新版游标：oldest按每组最小ID排序并记录最小ID，newest按每组最大ID排序并记录最大ID；成员仍按ID升序。这样一组跨越另一组时，暂停后不会跳过中间原帖。旧游标升级前会检测是否截断交错组，发现半组则停止，必须定向恢复；不能强行修改游标标记。`offset`不一定等于最后下载的成员ID；`imported`为本历史任务确认的新图数量，`reused`为已有成功记录，`download_bytes`仅统计已确认成功新帖的源下载量，失败时已用掉的流量不会计入。完成标记表示整个快照扫描完成，仍需检查跳过原因；不表示频道所有类型都已搬运。

游标、累计统计与跳过原因写入原`/opt/tg-import/state/imports.sqlite`，每个整帖确认后落盘。如果成功记录已写入而游标写入中断，下次复用该帖记录再推进，不重复发送；发送结果不确定则停在原游标，人工核对后处理。未完成照片组不会因为分页或网络异常被当成完成。

主动强制停止可用`docker stop -t 120 tg-import-history`，但发送中的中断可能留下`uploading`不确定状态，需人工核对；优先让`--max-posts`的小批任务在整帖边界自然暂停。不要删除会话、状态库或Bot API的TDLib数据，也不要改动任务对应的TG策略/相册。最低磁盘保留2GiB；服务端TG缓存沿用原清理器（例如SJC的15GiB压力上限），此工具不删除其缓存。


## 8. 删除测试媒体后，从最早历史重新搬运

本版本新历史任务默认`--order oldest`，按消息ID从小到大搬运，源帖组内也从小到大。`--until 22569`将上限固定为22569，消息缺失、非照片或不符合条件的帖子照常跳过，不要求消息ID连续。上限如果截断某个媒体组，会停止而不发送半组。

已建立的逆序任务不能原地翻转游标；即使尚未搬运也先重置采集状态。**先停止旧后台搬运，确认终端中没有其他采集进程，再更新图床与采集器。** 已停止的旧`tg-import-history`容器仍保存旧命令/镜像，不能用`docker start`启动它来测试新方向；可以为新任务使用不同容器名。

只有确认目标频道和图床中相应测试媒体已经删除、希望重新搬运时才执行reset。它只处理当前Token账号/策略/相册/源频道对应的采集记录，不删除其他来源，不操作TG频道，不清空图床数据库，不改API凭据或用户登录会话。

```bash
# 先预览对应采集记录数量，不修改：
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml \
  run --rm collector reset

# 确认后执行，自动先备份整个采集SQLite到state目录：
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml \
  run --rm collector reset --apply
```

有`uploading`不确定记录时拒绝重置，必须先人工核对，不能以重置掩盖可能仍在发送的任务。备份名形如`imports-before-reset-…sqlite`，保存原状态；`user.session`和私有env不动。

**此次还需要支持重新导入的图床镜像。** 图床会核对原已完成照片组的记录：全部仍在则复用；只删除一部分则停止；全部已删除才归档原完成状态并建立新组与新链接。原状态保留为`storage/app/tg-photo-import/*.retired-*`，未完成或发送结果不确定的状态不会自动重置，不盲目返回已经删除的旧链接。

准备就绪后先在最早历史试跑20个原帖：

```bash
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml \
  run --rm collector import-history --order oldest --until 22569 --max-posts 20 --download-kib 1024 --interval 30
```

验收后后台继续同一方向/上限，使用新容器名避免误启旧任务：

```bash
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml \
  run -d --name tg-import-history-oldest collector \
  import-history --order oldest --until 22569 --download-kib 1024 --interval 30
```

TG的`telegram_files`是文件路径→file_id映射，图床`images`是资源记录；另外还有R2归档映射、服务端照片组幂等状态、采集器去重/游标。这些不是同一张表。正常删除资源时应由应用维护映射；发现残留时先检查资源数、引用及归档任务，再备份后清理孤立行。不要因为删了测试媒体就整库清空，更不能删除存储策略、登录会话或Bot API的TDLib目录。reset本身不会删除这些服务端数据。


## 9. 交错照片组修复与此次5771～5774恢复

2026-10-19确认源帖交错为：5771和5773同组，5772和5774同组。旧版把5771、5772分别作为单张完成；提交5773时触发服务端“相同源帖内容发生变化”保护。不是通用网络500，不能简单删除不确定登记或跳过5773。

新版采集器使用完整元数据索引与组边界游标；下载单张默认120秒超时，最多3次尝试，可通过 `--download-timeout 120 --download-attempts 3` 设置。下载重试只在任何上传意图写入前发生；不会自动重试发送结果不确定的POST。元数据读取也有120秒超时，索引没有完整完成时不上传。

### 9.1 更新采集器，图床容器无需为本修复重建

确认 `tg-import-oldest-fast` 已退出。保留原 `/opt/tg-import/state`、私有env与会话。拉取已包含修复的分支提交并在SJC本地构建：

```bash
git -C /opt/tg-import/source pull --ff-only origin test/tg-channel-import
docker compose -p tg-import -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml build collector
```

### 9.2 仅用于本次已确认状态的定向恢复

脚本不是通用reset，默认只读预览。它要求旧游标恰为5772、唯一uploading恰为5773，两个旧done登记恰为5771和5772，图床编码与采集状态一致，并检查5773/5774还没有对应图床记录。任一条件不符即拒绝。

```bash
python3 /opt/tg-import/source/tools/tg-channel-import/recover-interleaved.py
python3 /opt/tg-import/source/tools/tg-channel-import/recover-interleaved.py --apply
```

执行时先取得采集锁和服务端用户导入锁，备份采集SQLite及两条旧登记到 `/opt/tg-import/recovery-interleaved-时间戳`；只退回两帖的本地记录/统计，将游标恢复到5770，并将两条旧done JSON改名保留审计。**不删除图床数据库记录、不删除TG消息、不删除图片或原链接，不改其他已完成记录或登录会话。**

之后重传两个完整原帖（5771+5773、5772+5774）。服务端完整验证后，同一图库组会改为完整成员；两个旧单张文件和TG消息仍然保留，因此TG会多出这两条历史单张，暂不自动清理。

### 9.3 必须用新版采集镜像重建任务

`docker start`旧容器仍运行旧镜像，不能修复此问题。先将已停止旧任务改名留作备份，再创建原名的新任务：

```bash
docker rename tg-import-oldest-fast "tg-import-oldest-before-interleaved-$(date +%Y%m%d-%H%M%S)"
docker compose -p tg-import -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml run -d --name tg-import-oldest-fast collector import-history --order oldest --until 22569 --download-kib 1024 --interval 3 --download-timeout 120 --download-attempts 3
docker logs --tail 30 -f tg-import-oldest-fast
```

首先显示 `indexing_source_posts`，扫描的是元数据，不是逐张下载；22,000条规模通常需等待一段时间，期间会打印进度。之后显示 `source_index_ready`、两个完整原帖的imported结果并继续后续消息。正常退出日志跟踪使用Ctrl+C。不用删除orphan容器或运行remove-orphans，原采集状态共享于 `/opt/tg-import/state`。
