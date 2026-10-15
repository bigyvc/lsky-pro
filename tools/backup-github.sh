#!/usr/bin/env bash
# New unified stack: online SQLite + configuration backup. Never backs up images.
set -euo pipefail
umask 077
BASE=/opt/lsky/backup
CONFIG="$BASE/config"
[[ -f "$CONFIG" ]] || { echo "缺少 $CONFIG（设置 BACKUP_REPO=owner/private-repo）" >&2; exit 1; }
source "$CONFIG"
: "${BACKUP_REPO:?设置 BACKUP_REPO}"
[[ "$BACKUP_REPO" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || exit 1
BRANCH=dayv-io
TOKEN="$BASE/github-token"
[[ -s "$TOKEN" ]] || { echo '缺少 github-token' >&2; exit 1; }
for command in python3 git tar flock docker; do command -v "$command" >/dev/null; done
exec 9>"$BASE/run.lock"
flock -n 9 || { echo '备份已在运行'; exit 0; }
# Refuse public repositories; do not log credentials or the API response.
python3 - "$TOKEN" "$BACKUP_REPO" <<'PY'
import json, sys, urllib.request
with open(sys.argv[1]) as f: token=f.read().strip()
req=urllib.request.Request('https://api.github.com/repos/'+sys.argv[2],headers={'Authorization':'Bearer '+token,'Accept':'application/vnd.github+json','User-Agent':'lsky-backup'})
try:
    with urllib.request.urlopen(req, timeout=30) as r: data=json.load(r)
    assert data.get('private') is True and data.get('full_name','').lower()==sys.argv[2].lower()
except Exception:
    sys.exit('备份停止：无法确认目标仓库为私有，请检查仓库名、PAT 和网络')
PY
# Verify the running application's actual database configuration, not merely a leftover file.
DB_CONFIG=$(docker exec "${BACKUP_CONTAINER:-lsky-pro}" php -r '
$app=require "/var/www/html/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db=$app->make("db")->connection();
echo json_encode(["driver"=>$db->getDriverName(),"path"=>$db->getConfig("database")]);
')
export DB_CONFIG
python3 - <<'PYDB'
import json, os, sys
try:
    cfg=json.loads(os.environ['DB_CONFIG'])
    assert cfg['driver']=='sqlite' and cfg['path']=='/var/www/html/database/database.sqlite'
except Exception:
    sys.exit('备份停止：当前应用不是默认路径 SQLite；请按实际数据库部署配置备份，不能只打包遗留文件')
PYDB
WORK=$(mktemp -d "$BASE/work.XXXXXXXX")
trap 'rm -rf -- "$WORK"' EXIT
STAGE="$WORK/stage"
mkdir -p "$STAGE"
python3 - "$STAGE" <<'PY'
import os, pathlib, shutil, sqlite3, sys, json, datetime
root=pathlib.Path(sys.argv[1])
required=['/opt/lsky/data/.env','/opt/lsky/stack.env','/opt/lsky/source/deploy/compose.stack.yaml','/etc/caddy/Caddyfile']
optional=['/opt/lsky/source/deploy/compose.stack-build.yaml','/opt/lsky/source/tools/telegram-api/Dockerfile','/etc/default/lsky-telegram-cache','/etc/systemd/system/lsky-telegram-cache.service','/etc/systemd/system/lsky-telegram-cache.timer','/usr/local/libexec/lsky-telegram-cache-cleaner.py','/opt/lsky/backup/config','/etc/systemd/system/lsky-github-backup.service','/etc/systemd/system/lsky-github-backup.timer','/opt/lsky/data/.code-revision']
for name in required+optional:
    p=pathlib.Path(name)
    if not p.is_file():
        if name in required: sys.exit('缺少必要配置：'+name)
        continue
    dst=root/name.lstrip('/'); dst.parent.mkdir(parents=True,exist_ok=True); shutil.copy2(p,dst)
# Snapshot includes telegram_files, archive mappings and all other SQLite tables.
src=pathlib.Path('/opt/lsky/data/database/database.sqlite')
if not src.is_file(): sys.exit('未找到默认 SQLite 数据库；请先核对实际数据库配置，不能生成不完整备份')
dst=root/str(src).lstrip('/'); dst.parent.mkdir(parents=True,exist_ok=True)
with sqlite3.connect(src.as_uri()+'?mode=ro',uri=True,timeout=30) as s, sqlite3.connect(dst) as d:
    s.backup(d)
    if d.execute('PRAGMA integrity_check').fetchone()[0]!='ok': sys.exit('SQLite 备份校验失败')
(root/'backup-info.json').write_text(json.dumps({'created_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'site':'dayv.io','contents':'SQLite and deployment configuration; no image objects, TG API state/cache or pending uploads'},ensure_ascii=False,indent=2))
PY
tar -czf "$WORK/dayv-io-backup.tar.gz" -C "$STAGE" .
[[ $(stat -c %s "$WORK/dayv-io-backup.tar.gz") -lt 94371840 ]] || { echo '备份超过90MiB，停止推送，请改用对象存储备份' >&2; exit 1; }
cat > "$WORK/askpass" <<'ASK'
#!/bin/sh
case "$1" in
  *Username*) printf '%s\n' x-access-token ;;
  *Password*) cat /opt/lsky/backup/github-token ;;
esac
ASK
chmod 700 "$WORK/askpass"
export GIT_ASKPASS="$WORK/askpass" GIT_TERMINAL_PROMPT=0
REPO="$WORK/repo"
git init -q -b "$BRANCH" "$REPO"
git -C "$REPO" config user.name lsky-backup
git -C "$REPO" config user.email backup@localhost
git -C "$REPO" remote add origin "https://github.com/$BACKUP_REPO.git"
# Check the old head first, then compare-and-swap only this site's branch.
OLD=$(git -C "$REPO" ls-remote --exit-code origin "refs/heads/$BRANCH") || {
  code=$?; [[ "$code" == 2 ]] || exit "$code"; OLD='';
}
OLD=${OLD%%$'\t'*}
cp "$WORK/dayv-io-backup.tar.gz" "$REPO/"
(cd "$REPO" && sha256sum dayv-io-backup.tar.gz > SHA256SUMS)
git -C "$REPO" add dayv-io-backup.tar.gz SHA256SUMS
TREE=$(git -C "$REPO" write-tree)
COMMIT=$(git -C "$REPO" commit-tree "$TREE" -m "dayv.io backup $(date -u +%FT%TZ)")
git -C "$REPO" update-ref "refs/heads/$BRANCH" "$COMMIT"
git -C "$REPO" push --force-with-lease="refs/heads/$BRANCH:$OLD" origin "refs/heads/$BRANCH"
# Keep the latest successfully uploaded archive locally as well.
cp "$WORK/dayv-io-backup.tar.gz" "$BASE/.latest.tar.gz.tmp"
mv "$BASE/.latest.tar.gz.tmp" "$BASE/latest.tar.gz"
printf '备份成功 %s；仓库 %s，分支 %s\n' "$(date -Is)" "$BACKUP_REPO" "$BRANCH"
