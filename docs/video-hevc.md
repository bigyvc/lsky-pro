# 实验分支：视频、音乐与 HEVC

分支 `test/video-hevc`，从主分支 `20d92f141e6e1454b71cbc950bea42ef9c3bd436` 创建。仅用于独立测试，不自动合并 master、不提升 latest、不更新 HK/SJC。

## 格式范围

| 上传后缀 | 校验与用途 |
|---|---|
| MP4、MOV | 检查真实视频轨道和尺寸，支持 HEVC/H.265、H.264 等允许的编码；网页可尝试播放 |
| WebM | 校验 VP8/VP9/AV1 视频轨道；网页可尝试播放 |
| M4V | MP4类容器，网页可尝试播放 |
| MKV、AVI、WMV、FLV、TS、M2TS、MTS、3GP | 校验真实视频轨道，保存原文件；分享页提供格式提示及可选下载，不自动转码 |
| M3U8 | UTF-8 HLS播放列表，最多1MiB，仅支持完整HTTPS地址；Safari等原生支持HLS的浏览器可播放 |
| HEVC、H265 | 仅接受真实 HEVC 裸码流，提供原文件下载，不承诺浏览器直接播放 |

HEVC 是编码，MP4/MOV 是容器。iPhone 拍摄的视频常是 HEVC 编码的 MOV/MP4，不必改名为 .hevc。改名不能转换编码；PNG等内容伪装成视频会被拒绝。播放器兼容性由设备、系统、容器和编码共同决定；Safari等支持HEVC也不表示任意裸流都可作为网页视频。

本分支不转码、不改变编码标记、不生成视频帧封面，不进行字幕处理或直播录制。保存原始字节，使用统一视频占位图。原图床的 images 表继续保存媒体记录，新增迁移给 images 表增加 video_download_enabled，默认 false；MD5/SHA1、尺寸、MIME、文件名、用户、相册等字段沿用。

## 如何启用

使用分支镜像搭建**独立测试站**，使用独立数据目录、R2桶及测试Bot/频道。不要直接将两个生产站改为分支镜像，也不要让同一Bot在两台本地API实例并行运行。

1. Actions通过后按该提交的 `ghcr.io/bigyvc/lsky-pro-docker:sha-完整提交SHA` 拉取测试镜像；分支构建不修改 latest。
2. 后台角色组“允许上传的文件类型”会出现 MP4/MOV/WebM/HEVC/H265/M4V/MKV/AVI/WMV/FLV/TS/M2TS/MTS/3GP/M3U8。默认组仍只有原图片后缀，新建角色的视频选项不默认勾选，已有角色不自动放行。
3. 勾选需要的格式。配置单文件上限，视频硬上限512MiB，M3U8列表最多1MiB；同时受角色单文件上限约束。游客限额继续由其角色组控制，不因视频功能而自动升高。
4. 视频角色须关闭图片审查与水印；程序明确拒绝绕过这两项要求。不提供视频内容审核。图片压缩/格式转换不会用于视频；R2直传仍要求角色质量100且不转换格式。
5. 管理员选择标准R2策略时视频也直传；游客和其他策略经服务器分片上传。沿用50MiB分片、R2每文件6并发、服务器每文件3并发、角色文件并发设置。
6. 上传完成返回原文件链接；媒体列表使用视频占位图，点击MP4/MOV/M4V/WebM/M3U8打开预览，其余格式显示提示；点击“分享页与下载设置”进入分享页。不能播放时不自动转码。

## 校验与资源限制

镜像安装Debian打包的FFmpeg以提供 ffprobe，但应用只调用 ffprobe 做元数据探测，不调用 ffmpeg 转码。真实回归用例才使用 ffmpeg 创建极小测试片。

- 本地上传探测实际暂存文件；强制相应解复用器，禁止把改名的播放列表交给自动协议解析。
- R2完成时先验证对象归属与分片长度，再用2分钟签名的HTTPS GET探测视频元数据。ffprobe可使用Range/seek找MP4/MOV元数据，PHP不把完整视频放入内存；这仍可能产生VPS到R2的额外读取，不能宣称完全零回源流量。
- HTTPS开启证书校验；不向浏览器或日志返回 ffprobe stderr（其中可能含签名URL）。签名URL由服务器内部生成，不接受访客输入任意URL。
- 每次进程20秒超时、10秒网络读取超时、1MiB探测窗口、1秒分析窗口、单次内存分配最多16MiB、一个解码线程。这不是进程总内存硬配额；某些大型/异常元数据视频可能被拒绝。
- 本机最多两个探测进程，其他请求最多等待15秒，繁忙时明确提示重试。任务/角色/存储容量检查继续生效。
- R2元数据探测失败不登记为成功，也不自动切服务器中转。已上传对象保留在可重试任务中，可重试或取消；过期清理由原任务机制处理。

## 读取、归档与备份

TG视频和开启原图保护的视频使用已有流式输出，支持Range/HEAD，不走图片解码、水印或整文件应用缓存。裸HEVC输出为附件。未开启原图保护的R2视频仍由R2域名返回，CORS沿用直传PUT设置。

视频记录可沿用R2→TG归档、校验及旧链接Worker回退；不更改原9.5GB阈值。仅本地逻辑回归不证明真实账户链路可用，生产前必须测试视频真实迁移、迁移后拖动播放及旧链接。

数据库备份继续包含媒体记录与TG/归档映射，不包含视频字节。本分支不自动改变缓存阈值或备份策略。

## 已加入回归

- ffmpeg生成64×48、0.2秒HEVC MP4/MOV、裸HEVC以及VP9 WebM，ffprobe读取真实轨道。
- TG上传、MIME/尺寸、映射、视频占位图、Range返回字节、伪装视频拒绝、审查策略不可绕过。
- R2会话、对象验证、视频元数据和登记；图像原有校验/重试用例保留。
- HEVC视频归档后校验TG副本、释放R2源文件，旧链接保留MIME与Range字节读取。
- 视频弹窗、裸流下载、文件名按文本展示、危险URL拒绝、关闭播放器释放src。

参考：[FFprobe官方文档](https://ffmpeg.org/ffprobe.html)、[Apple视频交付建议](https://developer.apple.com/documentation/webkit/delivering-video-content-for-safari)、[WebKit视频编码说明](https://webkit.org/blog/14445/webkit-features-in-safari-17-0/)。

## 视频分享页与下载开关

- 上传成功后，在链接区选择“音视频分享页”，复制形如 `https://你的图床/v/文件key` 的地址。URL选项仍是原文件直链，不会自动变为分享页。
- “我的图片”中点击视频，预览弹窗的“分享页与下载设置”也能进入此页。支持的容器显示播放器，其余显示格式说明。
- 下载按钮默认关闭，**既有视频也默认关闭**。文件所有者或管理员登录后，在该视频分享页勾选“显示下载按钮”并保存；每条媒体记录独立设置。
- 游客没有修改设置的权限；管理员可以管理游客记录。
- 分享页尊重记录的公开/私有权限。私有视频仅所有者及管理员可访问；所有者/管理员可在分享页显式勾选“公开分享”，同步修改记录的公开/私有状态；游客上传默认私有，需要管理员在分享页勾选“公开分享”才能给其他人访问。此功能不会自动公开任何既有记录。
- 隐藏下载按钮时，播放器附加 `controlslist=nodownload` 提示，独立下载端点返回403。按钮开启后，下载走图床流式接口，强制附件响应，并支持Range/HEAD；R2文件因此会经过VPS下载，播放仍沿用原地址。
- 分享页和下载响应禁止缓存，避免修改开关后继续展示旧的按钮。保存设置经过登录、CSRF和所有权校验。
- **这是下载入口开关，不是防下载/DRM**：无法阻止录屏、抓取播放请求或访问已知R2公开直链。关闭开关不会撤销旧直链或收回已下载的副本。

## M3U8限制

M3U8是播放列表，不是完整视频。上传的是本地 `.m3u8` 文件，不是粘贴一个远程地址。示例：

```m3u8
#EXTM3U
#EXT-X-TARGETDURATION:10
#EXTINF:10,
https://media.example.com/video/segment001.ts
#EXT-X-ENDLIST
```

1. 上传列表中的分片、子播放列表、密钥和初始化段等引用都必须是完整HTTPS地址。不支持相对路径、file/http等协议或HLS变量；不会将相对路径自动指向图床目录。
2. 应用只校验列表文本，不调用ffprobe读取列表，也不向这些外部地址发请求；因此校验成功不保证源视频能够播放。
3. 浏览器自行向源站读取。源站防盗链、CORS、鉴权和签名有效期，以及设备原生HLS支持决定实际播放结果；当前不内置hls.js、不转码。
4. R2→TG归档、GitHub数据库备份都不会把外部分片或密钥一并备份。下载按钮下载的也只是列表文件。
5. 不支持自动抓取整个HLS视频包、把频道历史视频导入图床或将外部直播录制到TG。

## HK测试版更新顺序

正式站已安装旧测试版时，先运行HK现有备份，并保留当前镜像作为回退。此分支通过Actions后，修改 `/opt/lsky/docker-compose.hevc.yaml` 中的镜像为新提交的不可变 `sha-完整SHA` 标签。

继续使用原HK的三个文件（不要改成SJC的stack配置）：

```bash
docker compose -p lsky   -f /opt/lsky/docker-compose.yaml   -f /opt/lsky/docker-compose.archive.yaml   -f /opt/lsky/docker-compose.hevc.yaml   pull lsky-pro

docker compose -p lsky   -f /opt/lsky/docker-compose.yaml   -f /opt/lsky/docker-compose.archive.yaml   -f /opt/lsky/docker-compose.hevc.yaml   up -d --no-deps --wait --wait-timeout 180 lsky-pro

docker exec -u www-data -w /var/www/html lsky-pro php artisan migrate:status
docker exec -u www-data -w /var/www/html lsky-pro php artisan view:clear
```

入口脚本会自动补入并执行迁移。检查 `2026_10_17_000001_add_video_download_enabled_to_images` 为已执行；如果显示Pending，先查看容器日志，再以www-data执行 `php artisan migrate --force`。不要删除数据库或数据卷，不需要重建TG API。

更新后：给管理员和游客角色分别勾选需要的新增后缀；测试图片、视频上传、分享页权限、下载开关、下载内容和进度拖动。关闭下载开关后再次访问下载端点应返回403；其他用户不应能修改设置。SJC不随此分支自动更新。

此次新增的是单个默认false字段，回退旧镜像可保留该字段；无需执行迁移回滚或恢复整个数据库。

## 音乐与音频上传

同一测试分支支持以下音频后缀。必须先在管理员/游客各自角色组勾选；既有角色不自动开放，新建角色也不会默认选中音视频。

| 后缀 | 用途 |
|---|---|
| MP3、FLAC、WAV、M4A、AAC、OGG、OPUS | 分享页提供音频播放器，实际播放取决于浏览器、容器和编码 |
| WMA、AIFF、AIF、APE | 保存原文件，分享页提供格式提示和可选下载，不自动转码 |

M4A是容器，可包含AAC或ALAC；程序校验真实音频轨道、编码、采样率和声道，不把音频当图片解码，不生成专辑封面。无音频轨道或伪装后缀的图片会被拒绝。只负责标准音频的存储与元数据识别，不提供解密；不支持QMC/NCM/KGM等加密音乐文件的解密。

管理员选择R2时沿用直传，游客按TG策略上传。文件大小仍同时受角色限额及512MiB硬上限约束；图片审查、水印策略不能被音频绕过。音频使用独立占位图、audio嵌入链接和播放器，支持原有Range/HEAD读取与R2→TG归档，不增加额外数据库迁移。

上传完成选择“音视频分享页”。音乐沿用分享页的公开/私有及默认关闭的下载入口开关；内部字段仍叫video_download_enabled，避免重复迁移。隐藏下载入口不能防止提取音频或访问公开直链，下载仍通过VPS流式输出。

回归覆盖：FFmpeg生成短音调测试MP3、FLAC、WAV、AAC/ALAC、OGG/Vorbis、Opus、WMA及AIFF的元数据、TG映射、R2登记、播放/下载提示和Range字节读取；MP3归档后校验旧链接。APE没有FFmpeg编码器，CI覆盖格式权限和伪装文件拒绝；本地通过可选LSKY_TEST_APE_FIXTURE读取真实APE样本验证上传，不将第三方音乐打包进仓库。校验仍是有界元数据探测，不等于逐帧完整解码验证。

参考：[FFmpeg格式文档](https://ffmpeg.org/ffmpeg-formats.html)。
