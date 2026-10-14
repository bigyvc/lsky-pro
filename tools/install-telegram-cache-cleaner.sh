#!/usr/bin/env bash
set -euo pipefail
[[ $(id -u) == 0 ]] || { echo '请以 root 运行'; exit 1; }
command -v python3 >/dev/null
command -v systemctl >/dev/null
container=${1:-lsky-pro}
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
[[ -f "$script_dir/telegram-cache-cleaner.py" ]] || { echo '缺少同目录 telegram-cache-cleaner.py'; exit 1; }
# Inspect mounts only; do not expose container credentials.
mounts=$(docker inspect "$container" --format '{{json .Mounts}}')
paths=$(printf '%s' "$mounts" | python3 -c '
import json,re,sys
m=json.load(sys.stdin); d={x["Destination"]:x for x in m}
for key in ["/var/www/html","/var/lib/telegram-bot-api"]:
    assert key in d and d[key]["Type"]=="bind", "需要确认图床与TG数据的实际bind挂载"
    assert re.fullmatch(r"/[A-Za-z0-9_./-]+",d[key]["Source"]), "路径需人工适配"
print(d["/var/www/html"]["Source"]+"/storage/app/tg-cache")
print(d["/var/lib/telegram-bot-api"]["Source"])
')
registry=$(printf '%s\n' "$paths" | sed -n '1p')
data=$(printf '%s\n' "$paths" | sed -n '2p')
[[ -d "$data" ]] || { echo 'TG 数据目录不存在'; exit 1; }
# Confirms the new application class is deployed and creates the private registry as www-data.
docker exec -u www-data -w /var/www/html "$container" php -r '
require "vendor/autoload.php"; $app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$lock=App\Services\TelegramCacheRegistry::lock(); fclose($lock);
'
install -d -m 755 /usr/local/libexec
install -m 755 "$script_dir/telegram-cache-cleaner.py" /usr/local/libexec/lsky-telegram-cache-cleaner.py
if [[ ! -e /etc/default/lsky-telegram-cache ]]; then
    cat > /etc/default/lsky-telegram-cache <<'ENV'
# Seconds and bytes. Pressure cleaning still protects files accessed in the last 5 minutes.
TG_CACHE_TTL=3600
TG_CACHE_MAX_BYTES=2147483648
TG_CACHE_MIN_FREE=2147483648
TG_CACHE_MIN_AGE=300
ENV
    chmod 600 /etc/default/lsky-telegram-cache
fi
cat > /etc/systemd/system/lsky-telegram-cache.service <<UNIT
[Unit]
Description=Evict registered Lsky Telegram media cache
[Service]
Type=oneshot
EnvironmentFile=/etc/default/lsky-telegram-cache
ExecStart=/usr/bin/python3 /usr/local/libexec/lsky-telegram-cache-cleaner.py --registry $registry --data $data --ttl \${TG_CACHE_TTL} --max-bytes \${TG_CACHE_MAX_BYTES} --min-free \${TG_CACHE_MIN_FREE} --min-age \${TG_CACHE_MIN_AGE}
UMask=0077
Nice=10
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
NoNewPrivileges=true
ReadWritePaths=$registry $data
UNIT
cat > /etc/systemd/system/lsky-telegram-cache.timer <<'UNIT'
[Unit]
Description=Check Lsky Telegram cache every five minutes
[Timer]
OnBootSec=5min
OnUnitActiveSec=5min
RandomizedDelaySec=30
[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload
systemctl enable --now lsky-telegram-cache.timer
python3 /usr/local/libexec/lsky-telegram-cache-cleaner.py --registry "$registry" --data "$data" --dry-run
echo '自动缓存清理已启用；上面是只读预览，默认每5分钟检查。'
