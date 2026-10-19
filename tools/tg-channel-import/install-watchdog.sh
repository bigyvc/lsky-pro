#!/usr/bin/env bash
set -euo pipefail
[[ $(id -u) == 0 ]] || { echo '请以root运行'; exit 1; }
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
python3 "$script_dir/watchdog.py"
install -d -m 755 /usr/local/libexec
install -m 755 "$script_dir/watchdog.py" /usr/local/libexec/tg-import-watchdog.py
cat > /etc/systemd/system/tg-import-watchdog.service <<'UNIT'
[Unit]
Description=Monitor Telegram photo import and recover safe download failures
After=docker.service
Requires=docker.service
[Service]
Type=oneshot
ExecStart=/usr/bin/python3 /usr/local/libexec/tg-import-watchdog.py --apply
UMask=0077
TimeoutStartSec=150
NoNewPrivileges=true
ProtectHome=true
PrivateTmp=true
UNIT
cat > /etc/systemd/system/tg-import-watchdog.timer <<'UNIT'
[Unit]
Description=Check Telegram importer every minute
[Timer]
OnBootSec=2min
OnUnitActiveSec=60s
AccuracySec=5s
[Install]
WantedBy=timers.target
UNIT
cat > /etc/logrotate.d/tg-import-watchdog <<'CONF'
/opt/tg-import/watchdog/events.jsonl {
    weekly
    rotate 4
    size 1M
    missingok
    notifempty
    compress
    create 0600 root root
}
CONF
systemctl daemon-reload
systemctl enable --now tg-import-watchdog.timer
echo '监控已启用：每分钟检查；不确定的发送只告警，不清理或重发。'
