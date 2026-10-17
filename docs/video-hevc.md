# 实验分支：视频、音乐与 HEVC

分支 `test/video-hevc` 提供图片、视频和音频上传。仅提交到测试分支，不自动合并master、不提升latest，也不自动更新HK/SJC。

独立分享页、公开分享表单、下载按钮及开关已经移除。上传后直接复制URL，用原文件链接播放或保存文件；这不是防下载系统。

## 支持的类型

| 后缀 | 处理方式 |
|---|---|
| MP4、MOV、M4V | 校验真实视频轨道与尺寸；可预览播放，取决于设备及编码 |
| WebM | 校验VP8/VP9/AV1视频轨道；可尝试预览 |
| HEVC、H265 | HEVC裸码流，保存原文件；通常需要外部播放器 |
| MKV、AVI、WMV、FLV、TS、M2TS、MTS、3GP | 校验真实视频轨道、保存原文件；预览显示格式提示 |
| M3U8 | UTF-8 HLS列表，最多1MiB，只支持完整HTTPS引用；原生HLS浏览器可尝试播放 |
| MP3、FLAC、WAV、M4A、AAC、OGG、OPUS | 校验真实音频轨道；提供音频预览，兼容性取决于设备及编码 |
| WMA、AIFF、AIF、APE | 保存并校验音频；预览显示格式提示，可使用支持的外部播放器打开URL |

HEVC/H.265是编码，MP4/MOV是容器；HEVC编码的MP4/MOV无需改名为.hevc。M4A可包含AAC或ALAC。更改扩展名不会转换编码；PNG等伪装成音视频会被拒绝。

不转码、不生成视频帧或专辑封面、不处理字幕、不录制直播，也不解密QMC/NCM/KGM等加密音乐文件。音视频使用固定占位图，不走图片解码。

## 角色与存储配置

1. 在对应角色组“允许上传的文件类型”中勾选需要的类型，管理员、普通用户、游客分别设置。既有角色不自动开放，新建角色也不会默认选中音视频。
2. 设置单文件限额。音视频同时受角色限额和512MiB硬上限限制；M3U8列表最多1MiB。
3. 音视频角色须关闭图片审查与水印，程序会明确拒绝绕过这两项要求。图片保存质量/转换不用于服务器中转的音视频；R2直传依旧要求角色质量100且不转换格式。
4. 管理员选择标准R2策略时沿用直传，游客和其他策略走服务器上传。沿用50MiB分片，R2每文件6并发，服务器每文件3并发，文件并发继续由角色配置。
5. 上传成功后复制“URL”，不再提供分享页链接。我的图片中的音视频卡片可打开播放器或格式说明，仅使用浏览器原生播放控件，没有自定义下载开关。

## TG及原图保护链接

TG文件及开启原图保护的文件，新生成的URL形如：

`https://你的图床/kVsRxA/云朵 - 我的楼兰.flac`

不加file前缀；中文和空格会URL编码。前面的记录key唯一定位记录，末尾显示原文件名，同名文件不用在显示名称中追加六位编码。物理存储原有防重名规则仍然保留。

文件名只是可读标签，绝不会用于拼接磁盘路径。归档改变物理文件名后，已经分享的命名链接仍可通过key访问同一记录。旧的`/key.后缀`短链接继续有效；刷新图片列表或重新获取链接即可获得新样式，无需重新上传。

所有角色适用。R2/WebDAV等关闭原图保护时仍保持策略原有直链；TG始终通过图床输出。原图保护不等同于私有访问鉴权，知道原文件URL的人仍可能读取和保存文件；本次移除功能不改变原文件直链的访问行为或记录的既有公开/私有字段。

旧的`/v/key`分享页及其下载/设置端点不再提供。请使用原文件URL。

## 读取、归档和缓存

TG音视频、开启原图保护的媒体沿用流式输出，支持Range/HEAD，不在PHP内存中缓存整个媒体。HEAD仅返回记录中的大小等元信息，304缓存命中及416范围错误不会触发TG下载；HEAD成功不代表远端文件已实测可读，验收应使用GET。裸HEVC按附件输出。未开启原图保护的R2仍直接从R2域名读取。

R2→TG归档继续校验副本、迁移记录和旧R2链接Worker回退，不改变9.5GB触发阈值。MP3与HEVC回归覆盖归档后旧链接的MIME及Range字节输出；测试替身不能替代真实账户的迁移、拖动播放验证。

不自动修改TG缓存阈值和定时清理设置。GitHub备份包含媒体记录及TG/归档映射，不包含媒体字节。

## M3U8范围

M3U8是列表，不是完整视频；上传的是本地.m3u8文件，不是远程URL。示例：

```m3u8
#EXTM3U
#EXT-X-TARGETDURATION:10
#EXTINF:10,
https://media.example.com/video/segment001.ts
#EXT-X-ENDLIST
```

上传列表中的分片、子列表、密钥与初始化段引用必须是完整HTTPS地址；不支持相对路径、file/http协议或HLS变量。程序只检查列表文本，不让ffprobe跟随引用，也不请求这些外部地址。

浏览器自行向源站读取，源站防盗链、CORS、鉴权、链接有效期及原生HLS支持决定播放结果。当前不内置hls.js，不上传文件夹/ZIP整包，不自动抓取或备份分片、密钥和外部直播；下载列表也不等于下载完整视频。

## 元数据与资源限制

应用只用ffprobe探测元数据，不调用ffmpeg转码。实际回归用ffmpeg生成短视频、短音调。

- 本地探测实际暂存文件，按后缀强制解复用器，禁止把播放列表交给自动协议解析。
- R2完成时校验对象归属和长度，再用内部生成的2分钟HTTPS签名URL探测。可能发生Range/seek读取，因此仍会有VPS到R2流量；PHP不会完整缓冲文件。
- HTTPS证书校验开启，不返回ffprobe stderr，以免泄露签名URL。不接受访客输入任意探测URL。
- 每进程20秒超时、10秒读取超时、1MiB探测窗口、1秒分析窗口、单次分配最多16MiB、一个解码线程。本机最多两个探测进程，其他请求最多等待15秒。
- 这些限制不是进程总内存硬配额，特殊大型元数据可能被拒绝；探测不是逐帧完整解码。
- R2探测失败不登记成功，也不自动改走服务器中转；保留可重试任务，取消/过期沿用原任务清理。

## HK测试版更新顺序

等Actions成功后，先运行HK原有备份，保存当前镜像和Compose测试覆盖文件。将`/opt/lsky/docker-compose.hevc.yaml`的镜像改为新提交的`sha-完整SHA`标签。

```bash
docker compose -p lsky \
  -f /opt/lsky/docker-compose.yaml \
  -f /opt/lsky/docker-compose.archive.yaml \
  -f /opt/lsky/docker-compose.hevc.yaml \
  pull lsky-pro

docker compose -p lsky \
  -f /opt/lsky/docker-compose.yaml \
  -f /opt/lsky/docker-compose.archive.yaml \
  -f /opt/lsky/docker-compose.hevc.yaml \
  up -d --no-deps --wait --wait-timeout 180 lsky-pro

docker exec -u www-data -w /var/www/html lsky-pro php artisan route:clear
docker exec -u www-data -w /var/www/html lsky-pro php artisan view:clear
```

本次移除不删除数据库字段、不回滚迁移。历史video_download_enabled字段和迁移文件仅为升级兼容保留，业务不再读取；新旧安装保持相同迁移历史。不需要重建TG API、删除数据卷或批量修改文件权限。

验证图片、视频和音乐上传，中文原文件名直链、旧短链接、播放器与进度拖动，以及归档后的旧R2链接。SJC不会自动更新。

## 回归说明

CI覆盖真实合成视频/音频的TG映射和读取、R2登记、MIME/尺寸、伪装文件拒绝、角色格式权限、SVG禁止、Range/HEAD、命名链接与旧短链接兼容、归档、原有图片行为，以及预览关闭后释放媒体资源。

APE没有FFmpeg编码器；CI覆盖权限和伪装拒绝，本地可通过LSKY_TEST_APE_FIXTURE读真实样本验证上传，不将第三方音乐打包进仓库。

参考：[FFprobe](https://ffmpeg.org/ffprobe.html)、[FFmpeg格式文档](https://ffmpeg.org/ffmpeg-formats.html)。


## 中文链接的显示与复制

TG命名链接会按标准对中文、空格等进行URL编码；%E5等序列不是乱码，也不表示文件被改名。上传结果的URL/Thumbnail URL栏仅将显示文字转换为可读中文，TG和R2采用相同展示逻辑。

点击链接行或“复制全部”时使用独立保存的规范URL，保留必要编码，不从显示文字反推地址。手动长按选择显示文字与使用复制按钮可能得到不同的文本表现，优先使用页面复制功能。HTML、Markdown、BBCode等输出保持对应语法，不对模板中的地址盲目解码。

显示文字通过文本节点写入；保留URL保留字符与控制字符的编码，防止文件名被解释成HTML或改变地址结构。无需改名或重新上传文件。

本轮完整检查及验证边界见 [2026-10-17 测试分支审查](branch-review-2026-10-17.md)。
