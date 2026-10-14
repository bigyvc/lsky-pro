# R2 达到 9.5GB 后全部归档到 Telegram：部署与配置

本功能已集成进应用和 Docker 镜像，默认关闭。启用后，R2 继续作为主上传存储；桶占用容量加正在上传的预留容量达到 9.5GB，或新上传预计超出阈值时，启动一轮归档。默认 `--retain=0`，持续迁移全部图床管理的 R2 图片到 Telegram，而不是仅降到 8.5GB。完成后，新图片继续存 R2，等待下一轮触发。

**代码集成、镜像构建成功、服务器更新、归档策略启用是四个不同步骤。** 必须完成本文中的 TG 服务、Worker 和启用检查，才会自动迁移。

## 目录

1. [工作方式与限制](#1-工作方式与限制)
2. [部署前确认](#2-部署前确认)
3. [更新图床镜像](#3-更新图床镜像)
4. [部署自建 Telegram Bot API](#4-部署自建-telegram-bot-api)
5. [配置 TG 存储策略或复用现有 Bot](#5-配置-tg-存储策略或复用现有-bot)
6. [配置 Cloudflare Worker 保留原链接](#6-配置-cloudflare-worker-保留原链接)
7. [开启 9.5GB 全部归档](#7-开启-95gb-全部归档)
8. [日常检查、停用与更新](#8-日常检查停用与更新)
9. [备份与恢复](#9-备份与恢复)
10. [故障排查](#10-故障排查)
11. [实现与验证范围](#11-实现与验证范围)

## 1. 工作方式与限制

| 项目 | 行为 |
|---|---|
| 触发容量 | 默认 9,500,000,000 字节，即十进制 9.5GB；不是每月刷新容量 |
| 容量统计 | 整个物理 R2 桶的对象大小，加图床正在上传的容量预留 |
| 新上传预计超限 | 拒绝本次上传并请求归档；空间释放后可重试 |
| 归档目标 | 默认 `--retain=0`，全部图床图片迁出 R2；可显式设置非零保留容量 |
| 自动执行 | 容器启动后每 300 秒检查；单进程串行，每轮最多 25 个对象，约 5 分钟时间预算 |
| 跨轮续跑 | 触发状态持久化；即使容量已下降到 9.5GB 以下，也继续到本轮目标完成 |
| 文件大小 | 本项目归档支持不超过 512MiB 的原图 |
| 数据验证 | 先检查源文件，再校验 TG 文件长度、MD5、SHA1；数据库切换和源对象删除可恢复 |
| 删除源文件 | TG 校验成功、图片记录切换完成、R2 ETag 未变化后才删除 |
| 失败处理 | 保留尚未删除的源对象，记录未完成任务，后续重试 |
| 完成后的上传 | 新图片仍存 R2；游客可继续使用 TG 策略 |
| 原图片链接 | R2 中仍存在的对象由 Worker 读取；已迁移对象回退到图床读取 TG |

“全部归档”指**图床数据库登记并由这些 R2 策略管理的图片**。未登记对象不会迁移或删除，空桶目标因此可能仍有其他文件。损坏、无法校验或超出本项目大小上限的图片不会强制删除，可能阻止这一轮完成。

归档会占用服务器的网络、暂存空间和 Bot API 下载缓存。每次复制前检查临时目录和 TG 共享目录，要求至少 `2 × 文件大小 + 512MiB` 空闲空间。**当前没有自动清理 TG 下载缓存的功能**；需定期检查磁盘，不要随意删除 TDLib 数据目录。TG 图片访问经过图床服务器，不保证具有 R2 直连的速度。

## 2. 部署前确认

以下操作适用于已有 Docker Compose 图床。示例部署路径为 `/opt/lsky/docker-compose.yaml`，容器为 `lsky-pro`。其他部署需替换实际路径与容器名。准备 Docker Compose v2、Python 3、curl 和 nano；准备 Telegram API ID/API Hash、Bot Token、频道，以及与 R2 桶同账号的 Cloudflare Worker 配置权限。

确认容器健康和 Compose 标签：

```bash
docker inspect lsky-pro --format '状态={{.State.Status}} 重启次数={{.RestartCount}} 健康={{if .State.Health}}{{.State.Health.Status}}{{end}}'
docker inspect lsky-pro --format '服务={{index .Config.Labels "com.docker.compose.service"}} 项目={{index .Config.Labels "com.docker.compose.project"}}'
```

本文后续命令在同一个 Bash 会话执行。根据容器标签设置变量，**不要猜测项目名或服务名**：

```bash
LSKY_CONTAINER=lsky-pro
LSKY_BASE=/opt/lsky/docker-compose.yaml
LSKY_EXTRA=/opt/lsky/docker-compose.archive.yaml
LSKY_PROJECT=$(docker inspect "$LSKY_CONTAINER" --format '{{index .Config.Labels "com.docker.compose.project"}}')
LSKY_SERVICE=$(docker inspect "$LSKY_CONTAINER" --format '{{index .Config.Labels "com.docker.compose.service"}}')
# 检查输出，应为原部署的项目和服务名；为空时停止，按实际部署适配。
printf '项目=%s 服务=%s\n' "$LSKY_PROJECT" "$LSKY_SERVICE"
docker compose -p "$LSKY_PROJECT" -f "$LSKY_BASE" config --services
```

先备份 SQLite、图床 `.env` 和现有 Compose。SQLite 在线备份应使用 Python `sqlite3.backup` 或等价方式，不应只复制正在写入的数据库文件。不要执行 `docker compose down -v`，不要更换数据卷路径或项目名。

## 3. 更新图床镜像

先确认 [GitHub Actions](https://github.com/bigyvc/lsky-pro/actions) 中对应提交的镜像构建为成功。`--retain=0` 和跨轮全部归档需要包含提交 `88f9b8e3d89e22b2a45402a25809dd87ea3b1daa` 或其后续版本的镜像。旧策略不会随镜像更新自动变为全部归档，仍需重新配置。

尚未部署 TG 补充文件时：

```bash
docker compose -p "$LSKY_PROJECT" -f "$LSKY_BASE" pull "$LSKY_SERVICE" &&
docker compose -p "$LSKY_PROJECT" -f "$LSKY_BASE" up -d --no-deps "$LSKY_SERVICE"
```

已部署补充文件时，必须同时带上两份配置：

```bash
docker compose -p "$LSKY_PROJECT" -f "$LSKY_BASE" -f "$LSKY_EXTRA" pull "$LSKY_SERVICE" &&
docker compose -p "$LSKY_PROJECT" -f "$LSKY_BASE" -f "$LSKY_EXTRA" up -d --no-deps "$LSKY_SERVICE"
```

等待容器变为 `healthy`。启动时会自动检测安装状态并应用缺失的数据库迁移；归档表是新增表，不改动原有图片记录。列出存储策略：

```bash
docker exec "$LSKY_CONTAINER" php /var/www/html/artisan r2:archive-config --list
```

该命令不输出密钥。记录实际 R2、TG 策略 ID。本文实际部署示例为 R2 `3`、TG `4`；其他安装不能照抄这两个编号。最后的 `[]` 表示尚未建立归档策略。

## 4. 部署自建 Telegram Bot API

### 4.1 为什么需要自建服务

Bot Token、频道 ID 用于 Bot 与频道；API ID/API Hash 用于运行 Telegram Bot API 服务。只在图床后台填写 API ID/API Hash，不能代替服务进程。官方 Bot API 的普通上传限制为 50MB，getFile 下载限制为 20MB；350MB 原图归档需要自建服务的 local 模式。

| 参数 | 保存位置 | 用途 |
|---|---|---|
| `TELEGRAM_API_ID` | `/opt/lsky/telegram-api/.env` | 运行自建 API |
| `TELEGRAM_API_HASH` | 同上 | 运行自建 API |
| Bot Token | 图床 TG 存储策略数据库 | Bot 身份 |
| 频道 Chat ID | 图床 TG 存储策略数据库 | 接收原图的频道 |
| Bot API 地址 | 图床 TG 存储策略 | `http://telegram-bot-api:8081` |
| 本地共享目录 | 图床 TG 存储策略 | `/var/lib/telegram-bot-api` |

API 凭据从 https://my.telegram.org 申请；不要把凭据发送到聊天、写进公共仓库或 Worker。

### 4.2 准备部署文件

仓库已有 [部署助手](../tools/prepare-telegram-api.sh) 和 [轻量 Dockerfile](../tools/telegram-api/Dockerfile)。可从仓库复制完整 tools 目录，或使用固定版本下载：

```bash
mkdir -p /opt/lsky/archive-setup/tools/telegram-api
LSKY_SETUP_REF=88f9b8e3d89e22b2a45402a25809dd87ea3b1daa
curl -fL --retry 3 "https://raw.githubusercontent.com/bigyvc/lsky-pro/$LSKY_SETUP_REF/tools/prepare-telegram-api.sh" \
  -o /opt/lsky/archive-setup/tools/prepare-telegram-api.sh &&
curl -fL --retry 3 "https://raw.githubusercontent.com/bigyvc/lsky-pro/$LSKY_SETUP_REF/tools/telegram-api/Dockerfile" \
  -o /opt/lsky/archive-setup/tools/telegram-api/Dockerfile &&
bash /opt/lsky/archive-setup/tools/prepare-telegram-api.sh "$LSKY_CONTAINER"
```

助手确认实际服务属于现有 Compose，保留原项目及网络，生成补充文件，不直接启动容器。已有补充文件时保留原文件，不覆盖。非 Compose、network_mode 或服务重名等情况需按实际部署适配。

服务使用 aiogram 项目维护的 **非官方 Docker 打包** `aiogram/telegram-bot-api:latest`，在此基础上调整文件用户为 UID/GID 33，与图床 www-data 相同，不在低配 VPS 编译 TG 源码。可验证后通过构建参数 `TELEGRAM_API_BASE_IMAGE` 固定基础镜像 digest。

生成的补充配置设置 `TELEGRAM_LOCAL=1`，创建内部网络，不对公网发布 8081 端口。宿主机 `/opt/lsky/telegram-api/data` 在两个容器中都映射为 `/var/lib/telegram-bot-api`，图床只读。使用新目录；不要复用其他 TG 服务数据目录或改为 777。

### 4.3 填写凭据并启动

```bash
nano /opt/lsky/telegram-api/.env
```

填写：

```ini
TELEGRAM_API_ID=你的数字API_ID
TELEGRAM_API_HASH=你的API_HASH
```

文件权限应为 600。Bot Token 不填这里。检查合并配置，不打印解析后的凭据：

```bash
docker compose -p "$LSKY_PROJECT" -f "$LSKY_BASE" -f "$LSKY_EXTRA" config --quiet &&
docker compose -p "$LSKY_PROJECT" -f "$LSKY_BASE" -f "$LSKY_EXTRA" \
  up -d --build --no-deps telegram-bot-api "$LSKY_SERVICE"
docker compose -p "$LSKY_PROJECT" -f "$LSKY_BASE" -f "$LSKY_EXTRA" ps
```

内网连通性验证：

```bash
docker exec "$LSKY_CONTAINER" php -r '
$s = @fsockopen("telegram-bot-api", 8081, $errno, $errstr, 5);
echo $s ? "TG API 内网连接成功\n" : "TG API 内网连接失败\n";
if ($s) fclose($s);
'
```

TCP 成功只代表服务可连接，不能替代 Bot 登录、频道权限及实际上传读取测试。

## 5. 配置 TG 存储策略或复用现有 Bot

### 方案 A：独立归档 Bot

可创建独立 Bot/频道，避免影响游客现用 Bot。后台新建 Telegram 存储策略，填写 Bot Token、频道 Chat ID、图床访问域名，API 地址填 `http://telegram-bot-api:8081`，本地共享目录填 `/var/lib/telegram-bot-api`。不需要绑定上传角色组。Bot 必须为频道管理员并具有发文件权限。

如果这个 Bot 已使用过官方 API，也需要先调用官方 `logOut` 再登录自建服务。

### 方案 B：复用现有游客 Bot 与频道（本次部署采用）

可以复用既有 TG 策略、Bot 和频道。**保留策略 ID，直接切换接口，不删除重建策略，不修改已有图片映射。** 同一 Bot 的所有 TG 策略必须一起切换；其他程序如果仍调用这个 Bot 的官方 API，也需协调迁移。

切换时暂时停止上传，先官方 `logOut`，再自建 API `getMe`/`getChat` 验证，最后更新策略的 `api_base` 和 `local_root`。官方规定退出后可立即登录自建服务，但 10 分钟内无法登录回官方云 API。

以下命令从数据库读取 Token，不在命令行、输出或历史记录中写入 Token。把 `4` 换成实际 TG 策略 ID。已经切换成功时不用重复执行；归档配置建立后，不要更换 Bot、频道或目标目录。

```bash
docker exec -i -u www-data -e TG_STRATEGY_ID=4 -w /var/www/html "$LSKY_CONTAINER" php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$stage = '检查策略';
try {
    $s = App\Models\Strategy::findOrFail((int)getenv('TG_STRATEGY_ID'));
    if ($s->key !== App\Enums\StrategyKey::Telegram) throw new RuntimeException();
    $token = (string)$s->configs->get('bot_token');
    $chat = (string)$s->configs->get('chat_id');
    $base = 'http://telegram-bot-api:8081';
    $root = '/var/lib/telegram-bot-api';
    if (!$token || !$chat || !is_readable($root)) throw new RuntimeException();
    $http = new GuzzleHttp\Client(['connect_timeout'=>10,'timeout'=>120,'http_errors'=>false]);
    $call = function ($api, $method, $params = []) use ($http, $token) {
        $r = $http->post($api.'/bot'.$token.'/'.$method, ['form_params'=>$params]);
        $data = json_decode((string)$r->getBody(), true);
        if (!($data['ok'] ?? false)) {
            echo 'API 返回错误码：'.(int)($data['error_code'] ?? $r->getStatusCode())."\n";
            throw new RuntimeException();
        }
        return $data['result'];
    };
    if (rtrim((string)$s->configs->get('api_base'), '/') !== $base) {
        $stage = '官方接口退出';
        $call('https://api.telegram.org', 'logOut');
        echo "官方接口退出成功\n";
    }
    $stage = '自建 API 登录';
    $call($base, 'getMe');
    $stage = '频道访问验证';
    $call($base, 'getChat', ['chat_id'=>$chat]);
    echo "自建 API 登录、频道访问成功\n";
    $stage = '更新策略';
    Illuminate\Support\Facades\DB::transaction(function () use ($token, $base, $root) {
        foreach (App\Models\Strategy::where('key', App\Enums\StrategyKey::Telegram)->get() as $item) {
            if ((string)$item->configs->get('bot_token') !== $token) continue;
            $c = $item->configs->all();
            $c['api_base'] = $base;
            $c['local_root'] = $root;
            $item->configs = collect($c);
            $item->save();
            echo '已切换 TG 策略 ID='.$item->id."\n";
        }
    });
    echo "切换完成\n";
} catch (Throwable $e) {
    echo '失败阶段：'.$stage."；停止操作，未输出密钥。\n";
    exit(1);
}
PHP
```

**脚本输出“已切换 TG 策略 ID=…”和“切换完成”后，已经直接保存到数据库，不需要再去后台重复修改。** 后台 → 存储策略 → 编辑原 TG 策略，应看到：

| 后台字段 | 切换后值 |
|---|---|
| Bot API 地址 | `http://telegram-bot-api:8081` |
| TG 本地共享目录 | `/var/lib/telegram-bot-api` |
| Bot Token、频道 Chat ID | 原值保留 |
| 策略 ID、已有图片记录 | 原值保留 |

如果采用手动配置，必须先完成官方 logOut 和自建 API 验证，再在同一个策略编辑页填写上述两项并保存；同一 Bot 的其他 TG 策略也一起修改。不要仅填 API 地址而遗漏共享目录，否则 local API 返回的绝对文件路径可能无法读取。

`getChat` 成功不代表一定有发送文件权限。切换成功后，用游客模式上传一张小图并打开，再打开一张已有 TG 图片；确认新旧文件均可访问。失败时先解决服务、权限或共享目录，不启用迁移。

## 6. 配置 Cloudflare Worker 保留原链接

修改数据库不会让现有公共 R2 URL 自动转向 TG，因此必须先配置 Worker。

1. Cloudflare → Workers 和 Pages → 创建 Worker，例如 `lsky-r2-archive`。
2. 编辑代码，完整替换为 [r2-archive-worker.mjs](../tools/r2-archive-worker.mjs)，部署。
3. 添加 **R2 存储桶绑定**：变量名 `BUCKET`，选择图床当前 R2 策略使用的同一个桶。
4. 添加以下文本变量。表中为本次部署示例，其他安装替换域名和实际 R2 策略 ID。

| 名称 | 示例值 | 说明 |
|---|---|---|
| `APP_ORIGIN` | `https://dayv.cc` | 图床 HTTPS 地址，与 R2 域名不同 |
| `SOURCE_STRATEGY_ID` | `3` | 原 R2 策略 ID，不是 TG ID |

5. 在域和路由设置添加 **Worker Route** `r2.dayv.cc/*`，区域选择 `dayv.cc`，失败模式选择 Fail closed（阻止）。绑定在同一 Cloudflare 账号/区域；域名须满足 Worker Route 的代理 DNS 要求。
6. **保留 R2 已有自定义域名**，不要删除或改成 Worker 自定义域名。这里是添加路由。
7. 打开原有 R2 图片，确认仍可访问。
8. 打开 `https://r2.dayv.cc/.__lsky_archive_probe`，预期：

```json
{"lsky_r2_archive":1,"source":3}
```

此探测验证 Worker 到图床的归档接口。启用命令还会取一张现有 R2 图片，对比 Worker 绑定的 ETag/大小与 R2 API 返回值，以验证桶绑定；仅探测 JSON 成功还不够。空桶需先上传一张小图。

Worker 不包含 Bot Token、R2 密钥或 API Hash。R2 对象存在时直接读取绑定；对象迁出后访问 `/archive/r2/3?path=旧路径`，由图床读取 TG。Worker 与旧路径映射须长期保留。不存在的文件返回 404，TG 不可读取时返回 503。

## 7. 开启 9.5GB 全部归档

先完成或取消所有正在进行的 R2 直传，并暂时停止其他上传；确认小图 TG 上传、旧 TG 图片和原 R2 图片正常。

使用实际策略编号：

```bash
LSKY_R2_ID=3
LSKY_TG_ID=4
docker exec -u www-data "$LSKY_CONTAINER" php /var/www/html/artisan r2:archive-config \
  --source="$LSKY_R2_ID" --target="$LSKY_TG_ID" \
  --trigger=9500000000 --retain=0 --links-ready --enable &&
docker exec -u www-data "$LSKY_CONTAINER" php /var/www/html/artisan r2:archive --dry-run
```

启用成功时输出类似：

```text
归档配置已保存；触发容量 9500000000 字节；每轮持续归档至全部图床图片迁出 R2。
```

只读检查示例：

```json
[{"policy":1,"used":746643883,"reserved":0,"migrated":0,"failed":0}]
```

| 字段 | 含义 |
|---|---|
| `policy` | 归档规则 ID |
| `used` | 实际整桶占用字节数 |
| `reserved` | 正在上传的容量预留 |
| `migrated` / `failed` | 本次执行的成功/失败数；dry-run 均为 0，不能证明实际迁移已通过 |
| `used_after` | 实际执行后桶占用；dry-run 不包含 |

无需额外配置 cron。容量尚未达到阈值且没有上传超限请求时，不立即迁移。配置已保存到数据库，容器重建后保留。

已有 8.5GB 停止策略也需要执行上述命令，以显式改为 `--retain=0`。非零模式例如 `--retain=8500000000` 会保留到 8.5GB；目标必须小于触发容量，触发容量不能超过 9.5GB。现有归档规则不可直接更换目标 Bot/频道，以免旧文件失去映射。

## 8. 日常检查、停用与更新

```bash
# 策略与保存的规则；enabled=true、target_bytes=0 表示全部归档已开启。
docker exec "$LSKY_CONTAINER" php /var/www/html/artisan r2:archive-config --list
# 只读容量检查。
docker exec -u www-data "$LSKY_CONTAINER" php /var/www/html/artisan r2:archive --dry-run
# 手动执行一轮；会真实复制、切换图片记录并在校验后删除 R2 源对象。
# 它仍受触发条件约束，不会因为手动调用就强制清空不足阈值的桶。
docker exec -u www-data "$LSKY_CONTAINER" php /var/www/html/artisan r2:archive --limit=25
# 停用自动归档。
docker exec -u www-data "$LSKY_CONTAINER" php /var/www/html/artisan r2:archive-config --source="$LSKY_R2_ID" --disable
# 磁盘容量及 TG 本地目录占用。
df -h /opt/lsky /tmp
du -sh /opt/lsky/telegram-api/data
```

手动与后台执行共用同一文件锁，避免重复迁移。停用后不要删除 Worker、TG 策略、Bot、频道或归档映射；已迁移图片仍依赖它们。正在执行的一轮可能继续完成，停用并非中断当前复制。

以后更新图床一直带两份 Compose：

```bash
docker compose -p "$LSKY_PROJECT" -f "$LSKY_BASE" -f "$LSKY_EXTRA" pull "$LSKY_SERVICE" &&
docker compose -p "$LSKY_PROJECT" -f "$LSKY_BASE" -f "$LSKY_EXTRA" up -d --no-deps "$LSKY_SERVICE"
```

只有更新 TG API 基础镜像时才需要针对 API 重新构建。不要用只带原 Compose 的命令重建图床，否则共享挂载和内部网络可能丢失。

## 9. 备份与恢复

已有每日 SQLite 在线备份会包含归档策略、归档任务、图片记录和 telegram_files 表，但**原来的备份脚本不一定包含新增部署文件**。请扩充备份清单：

| 文件或数据 | 用途 |
|---|---|
| `/opt/lsky/data/database/database.sqlite` | 图片、Bot 配置、TG 文件映射、归档状态 |
| `/opt/lsky/data/.env` | APP_KEY 等图床设置 |
| `/opt/lsky/docker-compose.yaml` | 原部署 |
| `/opt/lsky/docker-compose.archive.yaml` | TG 网络和共享目录挂载 |
| `/opt/lsky/telegram-api/.env` | Telegram API ID/API Hash |
| `/opt/lsky/telegram-api/Dockerfile` | TG 服务本地构建 |
| Worker 代码、绑定桶名、变量、路由 | 原 R2 链接回退 |
| TG API 数据目录 | 恢复服务状态与缓存，按空间制定备份方案 |

配置和数据库含密钥，仅存于受限私有备份。TG 云端原图、R2 图片本体不在 SQLite 备份中，不能把数据库备份当作图片完整副本；Bot API 缓存也不是唯一云端原图。需要独立图片备份时另行制定方案。

恢复时先停用写入，恢复匹配的数据库与 APP_KEY、两份 Compose、API 凭据和服务目录权限，再启动 TG API 与图床、恢复 Worker。检查 TG 老图、R2 老链接与归档规则后才恢复自动迁移。SQLite 热备由实际数据库路径决定；不要在运行中的服务上直接覆盖数据库。

不支持仅改 `strategy_id` 撤销归档。要迁回 R2，必须先恢复文件、校验数据并协调旧路径映射，再更新记录。

## 10. 故障排查

| 现象 | 检查与处理 |
|---|---|
| `--list` 末尾为 `[]` | 未建立规则；完成 TG/Worker 后执行启用命令 |
| 命令不存在或拒绝 `--retain=0` | 拉取包含全部归档改动的新镜像并重建图床，等待 healthy |
| 容器显示 health: starting | 等启动完成，再检查健康与重启次数；不能视为已正常运行 |
| TCP 内网连接失败 | 检查 API 容器、两份 Compose、网络和 8081；不用开放公网端口 |
| 官方 logOut 成功，自建登录失败 | 保存失败阶段，检查 API 凭据、TG 网络；不要立即反复切回官方，官方有 10 分钟限制 |
| getChat 成功但上传失败 | 进一步检查 Bot 发文件权限、频道 ID、真实上传返回结果 |
| TG 新图能上传但旧图打不开 | 保留原策略 ID/Token、数据库映射，检查 getFile、local 模式、共享目录及 UID 33 文件权限 |
| 旧链接 Worker 探测失败 | 检查路由、变量、APP_ORIGIN、图床 health 接口和 Worker 网络访问 |
| 探测 JSON 正常，启用失败 | 检查 BUCKET 是否为同一桶、source ID、R2 域名设置、测试图片是否真实存在、是否有未完成直传 |
| 很快再次触发归档 | 查看 target_bytes；全部模式应为 0，镜像更新不会改掉旧的 8.5GB 配置 |
| 归档到一半暂时停止 | 每轮限制 25 对象/约 5 分钟，下一轮续跑；检查 requested 与任务 phase/error |
| failed > 0 或磁盘不足 | 检查 TG 读写、暂存及缓存目录空间、文件权限、源哈希；失败源文件保留，不手工标记 done |
| 归档完成桶仍非空 | 未登记对象保留；无法校验、过大或不受管理的文件不强制删除 |
| TG 图片慢或返回 503 | 检查 TG/服务器网络、API 和本地读取；归档后流量经过图床 |
| 手动执行返回 busy | 后台持有归档锁，等待完成后再试，不删除锁文件绕过并发保护 |

迁移状态保存在 `r2_archive_jobs`：`pending → copied → switched → done`，另有 cancelled。不要删除任务表或手工改 phase；Worker 的旧路径回退依赖这些记录。

## 11. 实现与验证范围

- 配置命令：`r2:archive-config`；执行命令：`r2:archive`。
- 容量预留覆盖普通 R2 上传和 R2 直传；已归档旧路径永久保留，不给新文件复用。
- 迁移共享物理对象的全部图片记录，保留 ID、key、相册、标签、原文件名和容量计数。
- TG 输出流式读取，支持单段 Range、HEAD 和条件请求，不对大图解码。
- 容量与归档锁要求所有图床实例共享 `storage/app`。外部程序可能写同一桶；不要在归档期间覆盖对象。应用文件锁不能消除外部写入与 S3 删除之间的竞争。
- 自动测试覆盖 13→14 数据迁移保留旧数据、容量预留、失败保留源文件、恢复删除、350MiB 文件在 64MiB PHP 下流式迁移、跨轮低于阈值仍续跑、未登记对象保留、下一轮上传与旧链接/Range。
- 模拟测试不替代真实 TG 服务、权限、磁盘、Cloudflare 路由和实际速度检查。dry-run 不执行真实迁移。

参考：

- [Telegram 本地 Bot API](https://core.telegram.org/bots/api#using-a-local-bot-api-server)
- [Telegram logOut](https://core.telegram.org/bots/api#logout)
- [Telegram getFile](https://core.telegram.org/bots/api#getfile)
- [Telegram sendDocument](https://core.telegram.org/bots/api#senddocument)
- [Cloudflare Worker Routes](https://developers.cloudflare.com/workers/configuration/routing/routes/)
