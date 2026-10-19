#!/usr/bin/env bash
set -euo pipefail
[[ $(docker inspect tg-import-oldest-fast --format '{{.State.Running}}') == false ]] || { echo '原采集器仍在运行，停止'; exit 1; }
python3 - <<'PY'
import fcntl,json,sqlite3
from pathlib import Path
root=Path('/opt/tg-import/state')
with (root/'.lock').open('r+') as lock:
    fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    with sqlite3.connect((root/'imports.sqlite').as_uri()+'?mode=ro',uri=True) as db:
        if db.execute("SELECT COUNT(*) FROM imports WHERE phase='uploading'").fetchone()[0]:raise RuntimeError('仍有待确认上传，未恢复')
        jobs=[json.loads(r[0]) for r in db.execute('SELECT data FROM history_jobs')]
        if len(jobs)!=1 or jobs[0].get('offset')!=15395 or jobs[0].get('completed'):raise RuntimeError('不是本组试传后的进度，未恢复')
        rows=db.execute('SELECT message,phase FROM imports WHERE channel=1109579085 AND message BETWEEN 15395 AND 15398 ORDER BY message').fetchall()
        if rows!=[(n,'done') for n in range(15395,15399)]:raise RuntimeError('四张尚未全部保存，未恢复')
PY
docker rename tg-import-oldest-fast "tg-import-before-429-$(date +%Y%m%d-%H%M%S)"
docker compose -p tg-import -f /opt/tg-import/source/tools/tg-channel-import/compose.yaml run --no-deps -d --name tg-import-oldest-fast collector import-history --order oldest --until 22569 --download-kib 1024 --interval 10 --download-timeout 120 --download-attempts 3
rm -f /opt/tg-import/watchdog/paused
systemctl start tg-import-watchdog.service
echo '后台任务已按10秒间隔恢复；监控已恢复。'
