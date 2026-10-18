# 可选：频道图片导入（首轮20张）

这是独立的试验工具，位于`test/tg-channel-import`分支；不修改Lsky应用、Bot API或生产Compose，不提供新图库网站，也不启动常驻同步。

流程：Telegram用户会话读取频道 → 逐张下载 → Lsky上传API → 专用TG策略 → 图床记录。它不直接写Lsky数据库，不复用用户会话的file_id。图片网站展示可在确认导入可靠后另做。

## 能力与边界

- `login`只登录用户账号；`preview`只列出消息ID/文件大小，不下载图片、不请求Lsky。
- 默认扫描最近200条消息，选择最近20张图片。照片每条消息计一张，媒体组会有多条记录；不采集视频、SVG、任意文档、外链或频道文案。
- 支持Telegram照片，以及JPG/JPEG/PNG/WebP/GIF文档；默认每张最多25MiB，参数最多50MiB。
- 仅用户名形式的频道，不自动加入频道，不支持邀请链接；账号须能访问。频道开启内容保护时停止。可公开查看不代表允许转载，请确认采集与公开展示授权。
- 串行导入，成功后间隔3秒；最多导入100个候选/次。默认近期20张模式不是全量历史遍历，也不是实时同步；重复运行仍检查同一近期窗口，不会自动往更旧消息翻页。
- 持久化来源消息记录、内容SHA256及图床返回key/URL；同一目标范围的重复内容复用已记录结果。图床端也有独立内容去重。
- HTTP上传不自动重试。回包丢失、业务失败、哈希不一致或进程在提交后退出，会保留`uploading`状态并阻止自动重传。先核对专用账号“我的图片”及生成文件名，再处理状态，不能删除状态库后盲目全量重跑。
- 登录会话和导入状态存于宿主`/opt/tg-import/state`；不进入现有GitHub备份。会话相当于账号登录凭据，单独私密备份，不能提交到仓库或发送聊天。
- 单文件临时下载完成/异常后清理；强制中断留下的`download-*`目录在下一次获得独占锁后清理。至少保留2GiB可用空间，不操作Lsky/TG API缓存。

## 1. 准备图床

1. 新建专用普通用户和角色，启用站点API；验证邮箱（若站点要求）。
2. 专用角色绑定你选择的Telegram策略；若需独立图库频道，先新建私有频道并配置对应TG策略。Bot必须能向该频道发送文件。
3. 图片允许类型按需启用；限额至少25MiB。质量100、不转换格式、关闭水印和图片审查，以便保留原字节及核对哈希。不要修改普通游客角色。
4. 用该用户生成API Token。只将原始Token写入私有env，不加`Bearer `前缀。
5. 在导入账号后台创建“频道图片”相册，不必设为默认。工具按名称解析属于当前Token账号的相册ID并通过album_id上传；同名相册不唯一时必须额外指定ID。先更新图床到本测试分支对应的新版镜像；旧版profile没有相册能力标识，工具会在下载/上传之前停止。
6. 记录实际TG策略ID。工具先通过profile核对Token账号，上传由服务端检查策略权限；不能自动辨别策略类型，需手动确认该用户绑定的策略是TG。

## 2. SJC安装独立工具

在SJC执行；已有目录不要覆盖：

```bash
install -d -m 700 /opt/tg-import
git clone --branch test/tg-channel-import --single-branch \
  https://github.com/bigyvc/lsky-pro.git /opt/tg-import/source
install -d -o 10001 -g 10001 -m 700 /opt/tg-import/state
install -m 600 /opt/tg-import/source/tools/tg-channel-import/import.env.example \
  /opt/tg-import/import.env
nano /opt/tg-import/import.env
```

只在服务器填写：

```ini
TG_API_ID=你的API_ID
TG_API_HASH=你的API_HASH
SOURCE_CHANNEL=botmzt
LSKY_URL=https://dayv.io
LSKY_TOKEN=专用图床账号Token
LSKY_STRATEGY_ID=实际TG策略ID
LSKY_ALBUM_NAME=频道图片
LSKY_ALBUM_ID=0
IMPORT_AUTHORIZED=no
```

可复用现有API ID/API Hash，无需停用Bot API。用户登录会话与Bot API状态完全分开。私有env由root读取，容器用户10001仅访问专用state目录，不挂载现有图床或TG数据目录。

```bash
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml config --quiet
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml build
```

本工具在SJC本地构建，无需更新Lsky镜像。未开放任何公网端口；默认512MiB内存/1核上限。构建仍需拉取Python基础镜像与PyPI依赖。

## 3. 登录与只读预览

```bash
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml \
  run --rm -it collector login
```

在SJC交互终端填写用户手机号、验证码及两步验证密码；不要填写Bot Token，不要把验证码发给别人。随后预览：

```bash
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml \
  run --rm collector preview --limit 20 --scan 200
```

只输出候选消息ID/字节数。若没有权限或频道启用内容保护，工具停止；不通过绕过限制采集。

## 4. 明确执行20张导入

预览无误且已确认图片使用授权，再把私有env中的`IMPORT_AUTHORIZED`改为`yes`：

```bash
nano /opt/tg-import/import.env
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml \
  run --rm collector import --limit 20 --scan 200
```

`selected`是候选数量，不等于新增图片数：已有消息、重复内容和超限图片可能跳过。成功输出`imported`和图床key。图床生成文件名为`tg_频道ID_消息ID.后缀`，明确写入配置指定的相册，不改变账号默认相册；其他正常上传仍使用原默认相册。上传成功回包也会校验album_id。

检查专用用户的“我的图片”，抽查内容、原文件链接和TG消息。被图床去重的图片不一定新增TG消息。工具不会自动把文件改为公开图库列表，也不改变站点原文件URL的访问规则。

检查状态：

```bash
docker compose -p tg-import \
  -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml \
  run --rm collector status
```

若出现`uploading`，先停止再次导入，核对`tg_频道ID_消息ID.后缀`对应文件及图床日志。首版不提供自动不确定结果修复、无人值守定时同步或全历史批量拉取。

## 5. 验证与更新

本地回归：`python3 test/tg-channel-import.test.py`。覆盖预览零下载、HTTP200业务失败、哈希核对、来源/内容去重、断点跳过及不确定上传阻止重传。使用Telegram/Lsky替身；真实账号登录、频道读取、镜像构建和实际API导入仍需SJC验证。

工具后续更新需重新build；若新增服务端接口能力，还须同步更新Lsky镜像，见下方版本要求。`state`和`import.env`保留在源码目录外；不删除会话或导入状态。没有定时任务，运行结束自动退出。

## 指定相册的版本要求

本分支新增上传字段`album_id`：未提供时仍使用账号默认相册；0表示不进相册；正整数必须属于当前用户。游客、未知ID、他人相册和非法输入明确拒绝，不回退到默认相册。`GET /api/v1/profile`返回`upload_album_id_supported=true`，`POST /api/v1/upload`回包包含实际album_id，采集端会同时检查。

首次启用指定相册时需要更新Lsky镜像和独立采集工具两边。没有新数据库迁移，原有默认相册设置不变。更新前保留现有env/session/状态库；不要把新代码直接覆盖到生产卷里。已有导入记录不会自动移动相册。
