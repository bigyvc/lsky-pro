# Lsky Pro（自维护 Fork）

基于 [Lsky Pro](https://github.com/lsky-org/lsky-pro) 开源版深度定制的个人图床方案，全面面向自用优化，同时保留多用户支持。

**镜像地址：`ghcr.io/bigyvc/lsky-pro-docker:latest`**
同时支持 `amd64` 与 `arm64` 架构，部署时自动识别。

> 非官方自维护镜像。由于上游开源版已停止维护，应用源码 vendored 在本仓库 `src/` 里，构建不联网拉上游。**自用为主**：使用前请自行判断是否符合你的需求与合规要求；如有其他需求请自行 Fork 修改，本人不提供维护保障，不保证处理 issue 和 PR。如果喜欢还请点个 ⭐。

---

## 目录

- [功能特性](#功能特性)
- [界面预览](#界面预览)
- [安装步骤](#安装步骤)
  - [前置要求](#前置要求)
  - [Docker Compose 部署（推荐）](#docker-compose-部署推荐)
  - [docker run 部署](#docker-run-部署)
  - [全新安装](#全新安装)
  - [从上游 V2.1 无损升级](#从上游-v21-无损升级)
  - [配置反向代理](#配置反向代理)
- [配置说明](#配置说明)
  - [端口](#端口)
  - [Apache 并发与内存](#apache-并发与内存)
  - [PHP 与上传限制](#php-与上传限制)
  - [存储策略](#存储策略)
  - [角色组与原图保护](#角色组与原图保护)
  - [数据备份](#数据备份)
- [常见问题](#常见问题)
- [更新日志](#更新日志)
- [与上游的差异（开发向）](#与上游的差异开发向)
- [许可与致谢](#许可与致谢)

---

## 功能特性

- **开箱即用**：默认 SQLite，无需额外数据库服务；容器首次启动后打开浏览器就是安装向导。
- **低配友好**：Web 服务并发参数专为小内存 VPS 优化，每一项都可通过环境变量单独覆盖。
- **全平台适配**：修复上游在 iOS 上长按不出菜单的问题；手机端手势、二级菜单、菜单贴边避让全面优化。
- **界面焕新**：亮色 / 暗色 / 跟随系统三套主题，侧栏可折叠。
- **图片标签**：标签筛选、批量打标、卡片角标、标签管理（新建/改名/删除）。
- **图片编辑**：看图器内直接裁剪、旋转、翻转（JPG/JPEG/PNG），导出为副本不改原图。
- **批量删除**：图片管理页多选复选框 + 全选，一键批量删除（记录与物理文件一起删）。
- **Telegram 存储**：Bot API 以 document 形式发到指定频道存图，原图不压缩、容量近乎无限。[详细使用说明](docs/telegram-storage.md)
- **安全加固**：登录/注册接口限流、移除 SVG 上传、PHP 8.3 + 依赖升级到安全版本线、容器首次启动自动生成全新自签证书（替换镜像内置的公开默认私钥）。

### 与上游开源版对比

**功能：新增与移除**

| 项目 | 上游 | 本 fork |
|---|---|---|
| 图片标签 | 无 | ✅ 筛选、批量打标、角标、管理 |
| 图片编辑 | 无 | ✅ 裁剪 / 旋转 / 翻转 |
| 批量删除 | 无 | ✅ 图片管理页多选删除 |
| Telegram 存储 | 无 | ✅ 频道存图（须配合「原图保护」） |
| 全屏模式 | 无 | ✅ 看图器全屏沉浸模式 |
| 明暗主题 | 只有亮色 | ✅ 亮 / 暗 / 跟随系统 |
| 侧栏折叠 | 不支持 | ✅ 支持折叠、自动重绘排版 |
| SVG 上传 | 允许 | ❌ 移除（XSS 风险） |
| 画廊 | 有 | ❌ 移除（转为强制私有） |
| 后台「在线升级」 | 有（已停更） | ❌ 移除（随镜像升级） |

**修掉的上游 bug**

| 问题 | 本 fork |
|---|---|
| iPhone 长按不出菜单 | ✅ 长按 0.25 秒正常弹出 |
| 手机二级菜单点不到「复制链接」 | ✅ 点击展开正常复制 |
| 菜单靠屏幕边缘被切掉 | ✅ 自动避让、可滚动 |
| 手机浏览器地址栏不收起 | ✅ 正常收起 |

---

## 界面预览

<img width="1920" height="869" alt="6ac68bfc94a7f" src="https://github.com/user-attachments/assets/6ed36f64-5e26-4f9d-be2f-9d247abc3816" />

<img width="1920" height="869" alt="PixPin_2026-10-06_21-30-20" src="https://github.com/user-attachments/assets/00abe223-8c35-4b76-ae83-9bdb6679b49e" />

---

## 安装步骤

### 前置要求

- 安装了 Docker（20.10+）和 Docker Compose 的 Linux 服务器（任意发行版）
- 约 1.2GB 磁盘空间（镜像约 1GB）+ 图片数据空间
- 如需公网 HTTPS 访问：一个域名 + 一台反代（Nginx / Caddy，见下文）

### Docker Compose 部署（推荐）

新建目录，写入 `docker-compose.yaml`：

```yaml
services:
  lsky-pro:
    image: ghcr.io/bigyvc/lsky-pro-docker:latest
    container_name: lsky-pro
    restart: unless-stopped
    environment:
      - WEB_PORT=8089
    volumes:
      - ./data:/var/www/html          # 站点数据全在这个目录，备份它即可
    ports:
      - "127.0.0.1:8089:8089"          # 只绑本机，前面加反代（推荐）
```

然后：

```bash
docker compose up -d
docker compose logs -f   # 看启动日志，看到 Apache 启动即完成
```

> 端口写法 `127.0.0.1:8089:8089` 表示只监听本机，公网通过反代进入，比 `0.0.0.0` 更安全。局域网直连调试时可临时改成 `8089:8089`。

### docker run 部署

与上面等价的一条命令：

```bash
docker run -d --name lsky-pro --restart unless-stopped \
  -p 127.0.0.1:8089:8089 \
  -v "$PWD/data:/var/www/html" \
  -e WEB_PORT=8089 \
  ghcr.io/bigyvc/lsky-pro-docker:latest
```

### 全新安装

1. 容器启动后，浏览器访问 `http://<服务器IP>:8089/`（或反代后的域名），自动跳转到安装向导
2. 数据库选 **SQLite**（默认，路径留空即可），填管理员账号密码
3. 安装完成，开始使用

### 从上游 V2.1 无损升级

本 fork 与上游旧版的数据卷路径完全相同（`/var/www/html`），升级即换镜像：

1. `docker stop <旧容器名>` 停掉旧容器
2. **完整备份数据目录**（必做，`tar czf lsky-backup.tar.gz <数据目录>`）
3. `docker rm <旧容器名>` 删除旧容器
4. 用**相同的数据卷挂载路径**拉起本仓库镜像（挂载错了会 500，但数据不会丢，改对路径重起即可）
5. 新容器首次启动会自动检测并执行缺失的数据迁移（比如新增的 `tags`、`telegram_files` 表），无需手工操作；也可在启动后执行 `docker exec <容器名> php artisan migrate --force` 二次确认（应显示 Nothing to migrate）

### 配置反向代理

生产环境务必在前面放一层反代终结 TLS（容器只管 HTTP）。以域名 `img.example.com` 为例：

**Caddy**（自动申请证书，最省事）：

```caddy
img.example.com {
    reverse_proxy 127.0.0.1:8089
}
```

`systemctl reload caddy` 即可，证书自动申请续期。

**Nginx**：

```nginx
server {
    listen 443 ssl;
    server_name img.example.com;

    ssl_certificate     /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;

    client_max_body_size 512m;   # 与镜像的上传上限对齐

    location / {
        proxy_pass http://127.0.0.1:8089;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

> 镜像内置的 443 端口是**自签证书、仅供内网调试**，不要把它暴露到公网当 HTTPS 用。

---

## 配置说明

### 端口

| 环境变量 | 默认值 | 说明 |
|---|---|---|
| `WEB_PORT` | `8089` | 容器内 HTTP 端口（反代就反这个） |
| `HTTPS_PORT` | `8088` | 容器内 HTTPS 端口，自签证书，仅内网调试用 |

### Apache 并发与内存

默认是一套面向小内存单用户的参数，每一项都可用环境变量单独覆盖，不写就用默认：

| 环境变量 | 默认值 | 说明 |
|---|---|---|
| `APACHE_START_SERVERS` | `2` | 启动时预建的子进程数 |
| `APACHE_MIN_SPARE_SERVERS` | `1` | 空闲子进程下限 |
| `APACHE_MAX_SPARE_SERVERS` | `3` | 空闲子进程上限 |
| `APACHE_MAX_REQUEST_WORKERS` | `5` | 并发请求上限（内存占用的主要来源） |
| `APACHE_MAX_CONNECTIONS_PER_CHILD` | `5` | 单个子进程处理多少请求后回收（`0`=永不回收） |
| `APACHE_KEEP_ALIVE` | `Off` | 只接受 `On`/`Off`，`Off` 最省内存 |

> 不懂这些参数就别动，默认对个人自用足够；多用户场景可适当调大 `APACHE_MAX_REQUEST_WORKERS`。写错值只会退回默认值 + 日志警告，不会导致容器起不来。

### PHP 与上传限制

| 环境变量 | 默认值 | 说明 |
|---|---|---|
| `PHP_MEMORY_LIMIT` | `64M` | PHP 内存上限，处理大图/批量操作时可调大（如 `256M`），运行期生效、无需重建镜像 |

`post_max_size` 为 **544M**，`upload_max_filesize` 为 **512M**。注意角色组里还有独立的「最大文件大小」限制（默认 51200KB=50MB），按需在后台 → 用户组里调。

自签证书不随镜像发放也不进数据卷：每个容器首次启动自动生成。想用自己的证书，把 `.crt` / `.key` 挂载覆盖容器内 `/etc/apache2/ssl/lsky-selfsigned.crt` 与 `.key` 即可。

### 存储策略

后台 → 储存策略 → 创建，支持：本地、S3、阿里云 OSS、腾讯云 COS、七牛 Kodo、又拍云 USS、SFTP、FTP、WebDAV、MinIO、**Telegram**。一个策略可分配给多个角色组（编辑策略 → 角色组多选）；上传时没手动选则用该角色组的第一个策略。

**Cloudflare R2**（走 S3 驱动）：Endpoint 填 `https://<account-id>.r2.cloudflarestorage.com`，Region 可留空，Bucket、私钥按 R2 后台填，自定义域名填你绑定的 R2 公共域名。出站流量免费，适合做主存储。

**WebDAV**（如经 OpenList 挂载的网盘）：连接地址填 OpenList 的 WebDAV 入口（如 `http://<内网IP>:5244/`，容器内访问宿主机可用 docker 网关 `172.17.0.1`），路径前缀如 `/dav/img`，认证信息按 OpenList 的用户设置填。访问域名填经反代暴露的 OpenList 地址（如 `https://img.example.com/d/img`）。

**Telegram**：Bot API 以 document 形式发到指定频道，原图不压缩。**必须配合角色组的「原图保护」使用**（否则 Bot Token 会泄露），不要做直链策略。[详细使用说明 →](docs/telegram-storage.md)

### 角色组与原图保护

- 每个角色组可绑定多个存储策略；在**存储策略的编辑页**勾选允许使用的角色组（不是在用户组页）。
- **原图保护**（用户组 → 编辑角色组 → 原图保护）：开启后图片 URL 变成走应用中转的短链，不直接暴露后端存储地址。**Telegram 策略必须开**；R2 等直链策略建议不开（开了所有流量走你的服务器中转，又慢又吃带宽）。

### 数据备份

站点数据全在数据卷里（默认 `./data`），备份它就够了：

```bash
tar czf lsky-backup-$(date +%F).tar.gz -C <数据卷所在目录> data
```

关键是里面的 `database/database.sqlite`（所有图片记录、用户、策略配置）和 `.env`（`APP_KEY`，丢了加密数据全废）。图片文件本身在各存储后端（R2/Telegram/网盘），不在备份里——但没有数据库，这些文件就是一堆无名尸体。建议每天自动备份一次，参考脚本思路：SQLite 用在线热备（`VACUUM INTO` 或 Python `sqlite3.backup`，不停机）→ 打包 → 推到私有 Git 仓库。

---

## 常见问题

**Q: 拉起容器后 500？**
A: 九成是数据卷挂载路径不对（升级场景）。数据不会丢，把卷路径改对重起即可。

**Q: 上传大文件失败？**
A: 三处上限取最小值：PHP 的 512M、反代的 `client_max_body_size`（Nginx）/ `request_body max_size`（Caddy）、角色组的「最大文件大小」。逐一检查。

**Q: Telegram 策略上传失败 `chat not found`？**
A: Chat ID 填错（注意负号），或 Bot 不是频道管理员。见 [详细说明](docs/telegram-storage.md)。

**Q: 换了新镜像，旧数据还在吗？**
A: 在。数据全在挂载卷里，镜像只读。升级/重建容器只要卷路径不变就无损。

**Q: 后台图片管理页怎么批量删图？**
A: 勾选图片左上角复选框（支持全选），点顶部「批量删除」按钮，二次确认后记录与物理文件一起删除。

---

## 更新日志

- **超大文件上传修复**：图片质量重编码/水印处理改走「失败降级用原图」而不是直接 500（2026-10-14）
- **图片管理批量删除**：多选 + 全选 + 一键批量删除（2026-10-13）
- **Telegram 存储策略**：Bot 频道存图，配合原图保护使用（2026-10-12），[文档](docs/telegram-storage.md)
- **3.6.x**：图片编辑（裁剪/旋转/翻转）、看图器全屏、amd64+arm64 双架构、UI 细节修复
- **3.5.4**：上传限制放宽到 512M
- 完整历史见 git log。

---

## 与上游的差异（开发向）

- `src/` 是 vendored 的上游源码，本 fork 的所有修改都在上面直接改（改代码 = 改 `src/` 然后提交）。
- **改了哪些文件、为什么**，全部登记在清单里，随时自查：

  ```bash
  bash tools/diff-vs-upstream.sh
  ```

  它把 `src/` 与上游快照逐文件对比，并校验所有偏差都落在「已知偏离清单」内——不报错即改动恰好是清单里那些。

- `patches/` 是给人看的补丁存档（不参与构建）。
- Dockerfile 里有构建期自证：上游原样文件按 md5 校验、有意偏离的文件钉死 md5（改了不更新就构建失败）、以及内容指纹（改 `src/` 下任何文件都会让容器更新标记变化，触发数据卷代码同步）。

---

## 许可与致谢

- 应用本体：[lsky-org/lsky-pro](https://github.com/lsky-org/lsky-pro)（GPL-3.0）；`src/` 保留上游的 `LICENSE` 与全部署名。
- Docker 打包：fork 自 [HalcyonAzure/lsky-pro-docker](https://github.com/HalcyonAzure/lsky-pro-docker)（AGPL-3.0），本仓库自身的打包脚本沿用该许可。

## 大文件分片上传

新增50MiB分片、3个并发、失败重试和续传；大图保留原始图片，减少解码内存占用。PHP单文件上限512M，请求体上限544M。部署、清理、验证和R2迁移对接限制见 [分片上传说明](docs/chunk-upload.md)。
