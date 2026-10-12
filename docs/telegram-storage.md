# Telegram 存储驱动使用说明

本 fork 新增的第 11 种存储策略：把 Telegram 当作图床的存储后端，通过 Bot API
把图片以 `document` 形式发到你自己的频道保存。容量近乎无限、完全免费、
原图不压缩，适合做 **R2 的冷归档层**（R2 存热图、Telegram 存归档图）。

> ⚠️ 必读：Telegram 策略**必须**配合用户组的「原图保护」使用（走应用中转输出），
> **绝不能**用作直链策略。直链 URL 会暴露你的 Bot Token（见下文「安全警告」）。

---

## 1. 工作原理

| 动作 | 实现 |
|---|---|
| 上传 | `sendDocument` 发到指定频道（`disable_notification=true`，频道不弹通知），原图不压缩、不转码 |
| 寻址 | Telegram 不是按路径寻址的，`path → file_id / message_id` 的映射持久化在 `telegram_files` 表（按 `strategy_id` 隔离，多策略互不干扰） |
| 读取 | `getFile(file_id)` 拿到 `file_path`（缓存 50 分钟）→ 服务端代理下载 `https://api.telegram.org/file/bot<token>/<file_path>` → 流式输出给访客。Token 永远只在服务端 |
| 删除 | `deleteMessage` 删除频道消息，同时清理映射表。消息已不在频道里时只清映射、不报错 |
| 覆盖写 | 先上传新文件，成功后再替换映射、尽力清理旧频道消息——上传失败时旧文件不受影响 |

图片 URL 形态（开原图保护后）：`https://你的图床域名/{key}.{扩展名}`，
和存在 R2 / 本地时**完全一致**，归档迁移后外链永久不变。

---

## 2. 前置准备

### 2.1 创建 Bot，拿到 Token

1. 在 Telegram 里找 [@BotFather](https://t.me/BotFather)，发送 `/newbot`
2. 按提示设置 bot 的名字和用户名，完成后会收到一串 `Bot Token`
   （形如 `123456:AAExxx...`）——**这是最高机密，只填在服务端配置里**

### 2.2 创建频道并把 Bot 设为管理员

1. 新建一个频道（Channel，建议设为私密频道）
2. 进入频道设置 → 管理员 → 添加你刚创建的 bot 为管理员
   （需要"发送消息"权限；删图时还需要"删除消息"权限）

### 2.3 获取频道 Chat ID

- 把 [@GetTheirIDBot](https://t.me/GetTheirIDBot) 拉进频道（或转发一条频道消息给它），
  它会返回频道的 Chat ID（形如 `-1001234567890`，注意负号）
- 或：往频道发一条消息后访问
  `https://api.telegram.org/bot<你的Token>/getUpdates`，找 `channel_post.chat.id`

### 2.4 确认服务器能访问 Telegram

在跑图床的那台服务器上执行：

```bash
curl -s -o /dev/null -w "建连 %{time_connect}s, TLS %{time_appconnect}s\n" \
  https://api.telegram.org/ --max-time 15
```

能出时间就表示通。**国内服务器直连 `api.telegram.org` 不通**，
这一步失败的话 Telegram 策略在该服务器上不可用（上传/读取都会失败）。
自建 Bot API 服务可填到配置项 `api_base` 里绕过。

---

## 3. 部署步骤

### 3.1 更新代码并执行迁移

```bash
# 拉取包含 Telegram 驱动的新镜像并重建容器（按你自己的 compose 来），然后：
docker exec -w /var/www/html <容器名> php artisan migrate
```

这会创建 `telegram_files` 表。已有数据不受影响。

### 3.2 后台新建 Telegram 策略

后台 → 储存策略 → 创建储存策略 → 下拉选 **Telegram**，填写：

| 配置项 | 必填 | 说明 |
|---|---|---|
| 访问域名 | 是 | 占位字段（表单校验要求填 URL）。**必须配合原图保护使用**，实际访问走图床域名的短链，这里填你的图床域名即可 |
| URL Queries | 否 | 透传的 URL 额外参数，一般留空 |
| Bot Token | 是 | 2.1 拿到的 Token。只存服务端，绝不会出现在图片 URL 中 |
| 频道 Chat ID | 是 | 2.3 拿到的 ID，如 `-1001234567890`。Bot 必须是该频道管理员 |
| Bot API 地址 | 否 | 默认 `https://api.telegram.org`；自建 Bot API 服务填自己的地址 |

### 3.3 用户组开启「原图保护」（必须）

后台 → 用户组 → 编辑对应角色组 → 打开「原图保护」开关。

不开的话，图片 URL 会是 `策略访问域名/路径` 的直链形态——
而 Telegram 根本没有"公开直链"这个概念，图片会打不开；
更重要的是**直链方案会把 Bot Token 暴露出去**（见下文）。

### 3.4 测试

1. 上传一张图，确认成功且能在后台/前台打开
2. 去你的 Telegram 频道看一眼，应该多了一条 bot 发的 document 消息（无通知打扰）
3. 删一张测试图，确认频道里的对应消息也被删掉

---

## 4. 典型用法：R2（热）+ Telegram（冷归档）

这正是本驱动的设计目标：R2 享受 10GB/月免费额度与 Cloudflare 全球网络，
存量上去后把旧图归档到 Telegram（免费、近乎无限），R2 用量降下来，
全程外链不变（都走原图保护的短链）。

```sql
-- 查每个策略用了多少空间（images.size 单位是 KB）
SELECT s.name, SUM(i.size)/1048576 AS gb
FROM images i JOIN strategies s ON s.id = i.strategy_id
GROUP BY s.id, s.name;
```

归档流程（可写成 artisan 命令 + cron 定时跑）：

1. 按 `strategy_id` 选出 R2 策略下最旧的一批图片
2. 经 lsky 的 `ImageService`（或直接调 adapter）把文件写入 Telegram 策略
3. `UPDATE images SET strategy_id = <Telegram策略id> WHERE id IN (...)`
4. 确认无误后删除 R2 上的源文件（R2 删除免费、出站免费，搬运零成本）

> 注意：归档是"搬家"不是"复制"——第 4 步删了 R2 文件后，
> 这些图就只存在 Telegram（+ 你的频道）里了。见下文「风险提示」。

---

## 5. 限制与风险提示

- **单文件上限 50MB**：Bot API 的硬限制，超了上传直接失败
- **批量操作慢**：Bot API 约 30 条消息/秒，且上传速度一般只有几 MB/s；
  一次性归档几个 GB 会很久，适合日常增量归档，不适合一次性大迁移
- **ToS 灰色地带**：Telegram 没有"免费图床"这项服务，拿 bot 当仓库属于
  API 滥用的灰色地带。真被限流或封 bot，图就没了——**只做归档层，
  别当唯一副本**
- **file_id 与 bot 绑定**：Token 一重置（@BotFather /revoke），
  历史所有文件的 `file_id` 失效，图全部打不开。Token 务必保管好，
  不要手贱点 revoke
- **频道别删**：频道删了 = 所有文件物理删除，且不可恢复
- **读取延迟**：每次读图都要 `getFile` + 从 Telegram 下载（file_path 有 50 分钟缓存），
  首字节比 R2 慢。务必在图床域名前套 Cloudflare 开缓存（原图保护路由自带
  30 天 `Cache-Control`），或打开用户组的"图片缓存 TTL"

---

## 6. 安全警告：为什么必须开原图保护

Telegram 文件的真实下载地址是：

```
https://api.telegram.org/file/bot<你的Token>/<file_path>
```

URL 里**明文带着 Bot Token**。任何能看到这个 URL 的人就等于拿到了你的 bot
控制权（可以往你的频道发消息、删消息）。

本驱动的读取链路是：访客 → 你的服务器（原图保护短链）→ 服务端拼出上面的
真实地址下载 → 流式输出给访客。Token 只出现在**服务器→Telegram**这一段，
永远到不了访客的浏览器。

**不要**试图给 Telegram 策略配"直链访问域名"——技术上行不通，
强行拼出来的 URL 要么打不开，要么泄露 Token。

---

## 7. 故障排查

| 现象 | 可能原因 | 处理 |
|---|---|---|
| 上传失败，`chat not found` | Chat ID 填错，或 bot 不是频道管理员 | 检查 ID（含负号）、检查管理员权限 |
| 上传失败，`Unauthorized` | Bot Token 填错 | 重新从 @BotFather 获取 |
| 上传失败，文件超 50MB | Bot API 硬限制 | 换 R2/其他策略存大文件 |
| 图片打不开（404） | 原图保护没开；或迁移没跑（`telegram_files` 表不存在） | 开原图保护；进容器跑 `php artisan migrate` |
| 图片打不开，服务端报错 | 服务器连不上 `api.telegram.org` | 按 2.4 验证；国内服务器不可用，换海外机或自建 Bot API |
| 之前能看突然全挂 | Token 被 revoke 重置了 | 所有 file_id 失效，只能重新上传；以后别点 revoke |
| 删图后频道消息还在 | bot 缺"删除消息"的管理员权限 | 去频道设置里补上 |

---

## 8. 技术细节（给想改代码的人）

- 适配器：`src/app/Filesystem/TelegramAdapter.php`
  （实现 `League\Flysystem\FilesystemAdapter`，Flysystem 3.x）
- 映射模型/表：`src/app/Models/TelegramFile.php` / `telegram_files`
  （`strategy_id + path` 唯一；策略删除时级联清理映射，频道里的孤儿消息需手动清理）
- 配置枚举：`src/app/Enums/Strategy/TelegramOption.php`
- 接入点：`src/app/Services/ImageService.php` 的 `getAdapter()`、
  `src/app/Models/Strategy.php` 的 `DRIVERS`、
  `src/app/Http/Requests/Admin/StrategyRequest.php`、
  `src/resources/views/admin/strategy/{add,edit}.blade.php`
- 上传固定走 `sendDocument`（不用 `sendPhoto`），避免 Telegram 压缩转码，
  图床存的永远是原图
