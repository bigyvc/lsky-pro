# dayv.io：备份到 GitHub 私有仓库

适用于 `/opt/lsky/source/deploy/compose.stack.yaml` 统一部署。脚本为 `tools/backup-github.sh`，不会远程配置服务器，也不会自行创建仓库或 PAT。

## 包含与不包含

在线 SQLite backup API 生成一致性快照并执行 integrity_check，包含用户、策略密钥、telegram_files、R2 归档策略及路径映射。先读取运行容器的数据库配置，确认实际使用 SQLite，而不是仅看到磁盘上有一个旧文件。默认数据库必须位于 `/opt/lsky/data/database/database.sqlite`，路径不符则停止，不生成缺失数据库的备份。

配置包括图床 `.env`（含 APP_KEY）、`/opt/lsky/stack.env`、统一 Compose、可选本地构建 Compose、TG API Dockerfile、Caddyfile、已安装的 TG 缓存清理脚本及 systemd 配置、备份任务配置和应用代码版本标记（若存在）。

不包含 R2/TG 图片、TG API 的 TDLib 状态及下载缓存、进行中的分片、服务器全部程序或 Cloudflare 控制台中的 Worker 部署。Worker 代码在源码 `tools/r2-archive-worker.mjs`；请另存实际桶名、路由 `r2.dayv.io/*`、BUCKET 绑定、APP_ORIGIN=https://dayv.io、SOURCE_STRATEGY_ID=3。重建 API 需要原 API 凭据、原 Bot Token 和频道；不要换 Bot。GitHub 故障时不会更新本地 latest.tar.gz，也不会把失败报告为成功。

备份包未加密，含密钥，必须使用私有受限仓库；脚本会通过 GitHub API 确认 private=true 才开始。PAT 不进入备份，存放于 `/opt/lsky/backup/github-token`，权限600。90MiB 以上停止推送，数据库持续增长后应改用对象存储及独立保留策略。

## 1. 仓库与凭据

可沿用旧的私有 `bigyvc/lsky-backup`，也可新建私有仓库。创建细粒度 PAT，只选择此备份仓库，Contents=Read and write（Metadata 读取为基础权限）。令牌只粘贴到 VPS 文件，不发送到聊天。留意有效期，到期需要替换。

脚本只替换 `dayv-io` 分支为一个没有父提交的最新快照，不修改 main 或旧服务器分支。它不提供多版本恢复，也不能保证被替换的旧对象立即从 GitHub 物理删除。不要让别的备份任务使用同一分支。

## 2. 初始化 VPS

```bash
apt-get update && apt-get install -y git python3 ca-certificates util-linux
cd /opt/lsky/source
git pull --ff-only origin master
install -d -m 700 /opt/lsky/backup
printf '%s\n' 'BACKUP_REPO=bigyvc/lsky-backup' > /opt/lsky/backup/config
chmod 600 /opt/lsky/backup/config
# 仅首次创建，不要用此命令覆盖已保存的令牌
if [ ! -e /opt/lsky/backup/github-token ]; then
  install -m 600 /dev/null /opt/lsky/backup/github-token
fi
nano /opt/lsky/backup/github-token
bash /opt/lsky/source/tools/backup-github.sh
```

核对最后输出“备份成功”，并在私有仓库选择 dayv-io 分支，确认 dayv-io-backup.tar.gz 和 SHA256SUMS。出现错误先处理，不应将文件存在等同于本次备份成功。

## 3. 每天北京时间 03:00

首次手动成功后安装独立 timer；不影响旧服务器任务，也不依赖服务器时区。

```bash
cat > /etc/systemd/system/lsky-github-backup.service <<'UNIT'
[Unit]
Description=Lsky SQLite and configuration backup to private GitHub
Wants=network-online.target
After=network-online.target
[Service]
Type=oneshot
ExecStart=/bin/bash /opt/lsky/source/tools/backup-github.sh
UMask=0077
UNIT
cat > /etc/systemd/system/lsky-github-backup.timer <<'UNIT'
[Unit]
Description=Daily Lsky GitHub backup at 03:00 Asia/Shanghai
[Timer]
OnCalendar=*-*-* 03:00:00 Asia/Shanghai
Persistent=true
[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload
systemctl enable --now lsky-github-backup.timer
systemctl list-timers lsky-github-backup.timer
```

检查最近结果：`journalctl -u lsky-github-backup.service -n 30 --no-pager`。检查上次退出状态：`systemctl show lsky-github-backup.service -p Result -p ExecMainStatus`。timer 本身不发送失败通知，需定期查看或另接监控。

## 4. 恢复前验证

从 dayv-io 分支下载备份包和 SHA256SUMS，先在独立空目录执行 `sha256sum -c SHA256SUMS` 并解包检查。不要直接对正在运行的应用执行 `tar -C /`，以免覆盖活跃 SQLite/WAL。

恢复需先备份现状，停止 Lsky 和归档任务、TG 缓存清理任务，再还原数据库及匹配 APP_KEY 的 .env；清理旧 WAL/SHM 只能在所有数据库连接关闭且已备份后操作。恢复配置时核对宿主路径、UID/GID、网络、防火墙和域名。用原 Bot 凭据重建本地 TG API，先验证登录与 getFile、共享目录权限，再启动 Lsky。不能把新机安装向导生成的新数据库与旧 .env 混用。

恢复后先关闭自动归档，核对 R2 现存对象与 TG 映射，验证普通图片、TG 图片和已归档旧链接，再恢复自动归档和清理。备份只代表快照时刻；快照后新增记录不在备份中，且归档迁移可能已改变对象位置。因此定期备份不能代替恢复演练或对象保留策略。
