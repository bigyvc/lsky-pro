"""Optional user-session collector. Never touches the Lsky database or Bot API directly."""
import argparse
from contextlib import ExitStack
import asyncio
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import sqlite3
import sys
import tempfile
import time
from urllib.parse import urlsplit


class ImportFailure(Exception):
    pass


def channel_name(value):
    value = value.strip()
    if value.startswith('https://t.me/'):
        value = value.removeprefix('https://t.me/').rstrip('/')
    value = value.removeprefix('@')
    if not value or not all(c.isascii() and (c.isalnum() or c == '_') for c in value):
        raise ImportFailure('SOURCE_CHANNEL须为公开频道用户名，不支持消息链接或邀请链接')
    return '@' + value


def api_origin(value):
    p = urlsplit(value)
    if p.scheme != 'https' or not p.hostname or p.username or p.password or p.query or p.fragment or p.path not in ('', '/'):
        raise ImportFailure('LSKY_URL须为不含账号、路径或参数的HTTPS站点地址')
    return value.rstrip('/')


def digest(path):
    h = {name: hashlib.new(name) for name in ('md5', 'sha1', 'sha256')}
    with path.open('rb') as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b''):
            for item in h.values():
                item.update(chunk)
    return {name: item.hexdigest() for name, item in h.items()}


def api_data(response):
    # Never log response bodies, request headers, signed URLs or login credentials.
    if response.status_code != 200:
        raise ImportFailure('图床接口HTTP状态异常：' + str(response.status_code))
    try:
        result = response.json()
    except ValueError:
        raise ImportFailure('图床未返回JSON，请检查API开关及访问规则') from None
    if not isinstance(result, dict) or result.get('status') is not True:
        raise ImportFailure('图床接口业务失败，请在站点检查权限、限额和日志')
    return result.get('data')


class Lsky:
    def __init__(self, env):
        import httpx
        self.origin = api_origin(env.get('LSKY_URL', ''))
        token = env.get('LSKY_TOKEN', '').strip()
        if not token or any(c.isspace() for c in token):
            raise ImportFailure('请在私有env填写LSKY_TOKEN原始Token，不加Bearer前缀')
        self.strategy = int(env.get('LSKY_STRATEGY_ID', '0'))
        self.album_name = env.get('LSKY_ALBUM_NAME', '频道图片').strip()
        self.album_id = int(env.get('LSKY_ALBUM_ID', '0'))
        if not self.album_name or self.album_id < 0:
            raise ImportFailure('请填写LSKY_ALBUM_NAME，或同时指定有效LSKY_ALBUM_ID')
        if self.strategy < 1:
            raise ImportFailure('必须填写专用TG策略ID')
        self.client = httpx.Client(headers={'Authorization': 'Bearer ' + token, 'Accept': 'application/json'},
                                   follow_redirects=False, trust_env=False, timeout=httpx.Timeout(600, connect=15))

    def check(self):
        profile = api_data(self.client.get(self.origin + '/api/v1/profile'))
        if not isinstance(profile, dict) or not profile.get('email'):
            raise ImportFailure('无法确认导入账号身份')
        if profile.get('upload_album_id_supported') is not True:
            raise ImportFailure('请先更新图床到支持指定相册的版本，已停止且未上传文件')
        if profile.get('telegram_photo_groups_supported') is not True:
            raise ImportFailure('图床尚不支持照片组，请先更新，未上传文件')
        self.album_id = self.resolve_album()
        # The stock public strategies endpoint is guest-scoped; do not treat it as
        # authoritative for a Bearer user's strategy access. Upload enforces the role.
        return hashlib.sha256(json.dumps([self.origin, profile['email'], self.strategy, self.album_id]).encode()).hexdigest()

    def resolve_album(self):
        matches = []
        for page in range(1, 101):
            data = api_data(self.client.get(self.origin + '/api/v1/albums', params={'page': page}))
            if not isinstance(data, dict) or not isinstance(data.get('data'), list):
                raise ImportFailure('相册接口格式异常')
            for album in data['data']:
                if album.get('name') == self.album_name and (not self.album_id or int(album['id']) == self.album_id):
                    matches.append(int(album['id']))
            if not data.get('next_page_url'):
                break
        else:
            raise ImportFailure('相册列表过长，停止导入')
        if len(matches) != 1:
            raise ImportFailure('未找到唯一的目标相册；确认名称，重名时额外设置LSKY_ALBUM_ID')
        return matches[0]

    def upload_group(self, items, source_group):
        with ExitStack() as stack:
            files = [('files[]', (item['filename'], stack.enter_context(item['path'].open('rb')), 'image/jpeg')) for item in items]
            data = api_data(self.client.post(self.origin + '/api/v1/upload/telegram-photos',
                data={'strategy_id': str(self.strategy), 'album_id': str(self.album_id), 'source_group': source_group}, files=files))
        rows = data.get('images') if isinstance(data, dict) else None
        if not isinstance(rows, list) or len(rows) != len(items):
            raise ImportFailure('照片组回包数量异常，不自动重试')
        results = []
        for item, row in zip(items, rows):
            original = row.get('source', {})
            if (row.get('album_id') != self.album_id or not row.get('key') or not row.get('links', {}).get('url')
                or original.get('md5') != item['hashes']['md5'] or original.get('sha1') != item['hashes']['sha1']):
                raise ImportFailure('照片组来源哈希或实际相册不符，不自动重试')
            results.append({'key': row['key'], 'url': row['links']['url']})
        return results


class State:
    def __init__(self, path):
        self.db = sqlite3.connect(path)
        self.db.execute('''CREATE TABLE IF NOT EXISTS imports (
            scope TEXT, channel INTEGER, message INTEGER, phase TEXT, sha256 TEXT,
            result TEXT, PRIMARY KEY(scope,channel,message))''')
        self.db.execute('''CREATE TABLE IF NOT EXISTS history_jobs (
            scope TEXT, channel INTEGER, data TEXT, PRIMARY KEY(scope,channel))''')
        self.db.execute('''CREATE TABLE IF NOT EXISTS history_skips (
            scope TEXT, channel INTEGER, message INTEGER, reason TEXT,
            PRIMARY KEY(scope,channel,message))''')
        self.db.commit()

    def job(self, scope, channel):
        row = self.db.execute('SELECT data FROM history_jobs WHERE scope=? AND channel=?', (scope, channel)).fetchone()
        return json.loads(row[0]) if row else None

    def checkpoint(self, scope, channel, job, skipped=None):
        with self.db:
            if skipped:
                members, reason = skipped
                self.db.executemany('INSERT OR REPLACE INTO history_skips VALUES (?,?,?,?)',
                    [(scope, channel, member.id, reason) for member in members])
            self.db.execute('INSERT OR REPLACE INTO history_jobs VALUES (?,?,?)', (scope, channel, json.dumps(job)))

    def get(self, scope, channel, message):
        return self.db.execute('SELECT phase,result FROM imports WHERE scope=? AND channel=? AND message=?',
                               (scope, channel, message)).fetchone()

    def save(self, scope, channel, message, phase, sha, result=None):
        self.db.execute('INSERT OR REPLACE INTO imports VALUES (?,?,?,?,?,?)',
                        (scope, channel, message, phase, sha, json.dumps(result) if result else None))
        self.db.commit()


def select_posts(messages, limit, scan):
    # Telegram albums are consecutive messages. Never split one at the selection boundary.
    groups = {}
    for msg in messages:
        gid = getattr(msg, 'grouped_id', None)
        key = ('g', gid) if gid is not None else ('s', msg.id)
        groups.setdefault(key, []).append(msg)
    boundary = getattr(messages[-1], 'grouped_id', None) if len(messages) == scan and messages else None
    selected = []; count = 0
    for key, members in groups.items():
        if key[0] == 'g' and key[1] == boundary:
            continue  # The scan window may have cut this source album.
        if not all(getattr(m, 'photo', None) and not getattr(m, 'noforwards', False) for m in members):
            continue  # Do not silently drop videos/documents from mixed source albums.
        if len(members) > 10:
            raise ImportFailure('源照片组超过10张，停止而不拆分')
        if count + len(members) > limit:
            break
        selected.append((key, sorted(members, key=lambda m: m.id)))
        count += len(members)
        if count == limit:
            break
    return selected


def post_key(msg):
    gid = getattr(msg, 'grouped_id', None)
    return ('g', gid) if gid is not None else ('s', msg.id)


def skip_reason(members, max_bytes):
    if any(getattr(msg, 'noforwards', False) for msg in members):
        return 'protected'
    if not all(getattr(msg, 'photo', None) for msg in members):
        return 'non_photo_or_mixed'
    if any(not 0 < int(getattr(getattr(msg, 'file', None), 'size', 0) or 0) <= max_bytes for msg in members):
        return 'unknown_or_oversize'
    return None


class DownloadPacer:
    """Bound average source download speed; at most one Telegram chunk of burst."""
    def __init__(self, kib):
        self.rate = kib * 1024

    def callback(self):
        started = time.monotonic()
        async def progress(received, total):
            delay = received / self.rate - (time.monotonic() - started)
            if delay > 0:
                await asyncio.sleep(delay)
        return progress


async def import_post(client, entity, members, root, state, scope, api, args):
    old = [state.get(scope, entity.id, msg.id) for msg in members]
    if any(row and row[0] == 'uploading' for row in old):
        raise ImportFailure('该源帖存在结果不确定的上传，请先人工核对，不自动重发')
    if all(row and row[0] == 'done' for row in old):
        return 'reused', 0
    if any(row for row in old):
        raise ImportFailure('源帖已有部分单张导入记录，停止；不会重复发送或拆组')
    ceiling = min(args.max_bytes, 8 * 1024**2)
    sizes = [int(getattr(msg.file, 'size', 0) or 0) for msg in members]
    if shutil.disk_usage(root).free < sum(sizes) + 2 * 1024**3:
        raise ImportFailure('剩余磁盘不足，至少保留2GiB')
    source_group = str(entity.id) + ':' + post_key(members[0])[0] + str(post_key(members[0])[1])
    downloaded = 0
    with tempfile.TemporaryDirectory(prefix='download-', dir=root) as temp:
        items = []
        for msg in members:
            path = Path(temp) / ('mzt-' + str(msg.id) + '.jpg')
            options = {'file': str(path)}
            if getattr(args, 'download_kib', 0):
                options['progress_callback'] = DownloadPacer(args.download_kib).callback()
            await client.download_media(msg, **options)
            if not path.is_file() or not 0 < path.stat().st_size <= ceiling:
                raise ImportFailure('下载照片为空或超限，停止整组')
            downloaded += path.stat().st_size
            items.append({'path': path, 'filename': path.name, 'hashes': digest(path)})
        # All member intents become durable together before a potentially uncertain POST.
        with state.db:
            for msg, item in zip(members, items):
                state.db.execute('INSERT OR REPLACE INTO imports VALUES (?,?,?,?,?,?)',
                    (scope, entity.id, msg.id, 'uploading', item['hashes']['sha256'], None))
        results = await asyncio.to_thread(api.upload_group, items, source_group)
        if len(results) != len(members):
            raise ImportFailure('照片组回包不完整，不自动重发')
        with state.db:
            for msg, result in zip(members, results):
                state.db.execute('UPDATE imports SET phase=?,result=? WHERE scope=? AND channel=? AND message=?',
                    ('done', json.dumps(result), scope, entity.id, msg.id))
        for msg, result in zip(members, results):
            print(json.dumps({'imported': msg.id, 'key': result['key'], 'source_group': source_group}), flush=True)
    await asyncio.sleep(getattr(args, 'interval', 3))
    return 'imported', downloaded


async def collect(client, entity, args, root, env, api=None):
    state = State(root / 'imports.sqlite') if api else None
    scope = api.check() if api else None
    messages = [msg async for msg in client.iter_messages(entity, limit=args.scan)]
    posts = select_posts(messages, args.limit, args.scan)
    count = 0
    for group, members in posts:
        count += len(members)
        source_group = str(entity.id) + ':' + group[0] + str(group[1])
        print(json.dumps({'source_group': source_group, 'messages': [m.id for m in members], 'action': args.command}), flush=True)
        if api:
            reason = skip_reason(members, min(args.max_bytes, 8 * 1024**2))
            if reason:
                print(json.dumps({'skipped': [msg.id for msg in members], 'reason': reason}), flush=True)
                continue
            await import_post(client, entity, members, root, state, scope, api, args)
    print(json.dumps({'selected': count, 'posts': len(posts), 'mode': args.command}), flush=True)


async def history_posts(client, entity, snapshot, offset, order='newest'):
    """Telethon paginates; keep an album across page boundaries, never across failures."""
    ascending = order == 'oldest'
    above = [msg async for msg in client.iter_messages(entity, limit=1, offset_id=snapshot, reverse=True)] if snapshot else []
    boundary_group = getattr(above[0], 'grouped_id', None) if above else None
    pending = []; key = None; previous = offset if ascending else (offset or snapshot + 1)
    options = dict(limit=None, offset_id=offset, max_id=snapshot + 1, wait_time=1)
    if ascending:
        options['reverse'] = True
    async for msg in client.iter_messages(entity, **options):
        if msg.id > snapshot or (msg.id <= previous if ascending else msg.id >= previous):
            raise ImportFailure('历史分页顺序异常，停止且不推进当前组游标')
        previous = msg.id
        current = post_key(msg)
        if current[0] == 'g' and current[1] == boundary_group:
            raise ImportFailure('历史上限截断了原媒体组，停止而不发送半组；需选择完整原帖边界')
        if pending and current != key:
            yield key, sorted(pending, key=lambda member: member.id)
            pending = []
        key = current
        pending.append(msg)
        if len(pending) > 10:
            raise ImportFailure('源媒体组超过10条，停止而不拆分')
    if pending:
        yield key, sorted(pending, key=lambda member: member.id)


def history_stats():
    return {'posts': 0, 'messages': 0, 'eligible_photos': 0, 'source_bytes': 0,
            'imported': 0, 'reused': 0, 'download_bytes': 0, 'skipped_photos': 0, 'skip_posts': {}}


def add_stats(stats, members, reason, outcome=None, downloaded=0):
    stats['posts'] += 1; stats['messages'] += len(members)
    photos = sum(bool(getattr(msg, 'photo', None)) for msg in members)
    if reason:
        stats['skipped_photos'] += photos
        stats['skip_posts'][reason] = stats['skip_posts'].get(reason, 0) + 1
    else:
        stats['eligible_photos'] += len(members)
        stats['source_bytes'] += sum(int(msg.file.size) for msg in members)
        if outcome:
            stats[outcome] += len(members)
        stats['download_bytes'] += downloaded


def report_history(job, mode):
    print(json.dumps({'mode': mode, **job}, ensure_ascii=False), flush=True)


async def history(client, entity, args, root, api=None):
    state = State(root / 'imports.sqlite') if api else None
    scope = api.check() if api else None
    job = state.job(scope, entity.id) if state else None
    ceiling = min(args.max_bytes, 8 * 1024**2)
    order = getattr(args, 'order', 'newest')
    until = getattr(args, 'until', 0)
    if job and job.get('order', 'newest') != order:
        raise ImportFailure('已有历史任务方向不同；先备份并reset采集状态，不能直接翻转旧游标')
    if job and until and job.get('requested_until', job['snapshot']) != until:
        raise ImportFailure('已有任务的快照上限不同，不能中途更改')
    if job and job['ceiling'] != ceiling:
        raise ImportFailure('不能在同一历史任务中改变文件大小筛选条件')
    if not job:
        latest = [msg async for msg in client.iter_messages(entity, limit=1)]
        snapshot = min(latest[0].id, until) if latest and until else (latest[0].id if latest else 0)
        job = {'order': order, 'requested_until': until, 'snapshot': snapshot, 'offset': 0, 'completed': False, 'ceiling': ceiling,
               'started_at': int(time.time()), 'updated_at': int(time.time()), 'stats': history_stats()}
        if state:
            state.checkpoint(scope, entity.id, job)
    if job['completed']:
        report_history(job, 'already_completed'); return
    report_history(job, 'resuming' if job['offset'] else 'starting')
    processed = 0; last_report = time.monotonic()
    async for group, members in history_posts(client, entity, job['snapshot'], job['offset'], order):
        if state and any((row := state.get(scope, entity.id, msg.id)) and row[0] == 'uploading' for msg in members):
            raise ImportFailure('历史任务遇到发送结果不确定的记录，停止且不推进游标，请人工核对')
        reason = skip_reason(members, ceiling)
        outcome = None; downloaded = 0
        if api and not reason:
            outcome, downloaded = await import_post(client, entity, members, root, state, scope, api, args)
        add_stats(job['stats'], members, reason, outcome, downloaded)
        job['offset'] = (max if order == 'oldest' else min)(msg.id for msg in members)
        job['updated_at'] = int(time.time())
        if state:
            state.checkpoint(scope, entity.id, job, (members, reason) if reason else None)
        processed += 1
        if time.monotonic() - last_report >= 10 or processed % 100 == 0:
            report_history(job, args.command); last_report = time.monotonic()
        if getattr(args, 'max_posts', 0) and processed >= args.max_posts:
            report_history(job, 'paused_at_post_limit'); return
    job['completed'] = True; job['updated_at'] = int(time.time())
    if state:
        state.checkpoint(scope, entity.id, job)
    # Payload estimates only: origin download + Bot upload + target verification.
    # Public API routing may add a second egress/ingress pair.
    job['estimated_network_bytes'] = {'local_api_path': 3 * job['stats']['source_bytes'],
                                      'public_api_roundtrip': 5 * job['stats']['source_bytes']}
    report_history(job, 'completed')


def reset_import_state(root, scope, channel, apply=False):
    """Only this account/album/channel's collector bookkeeping, never Telegram sessions."""
    state = State(root / 'imports.sqlite')
    counts = {name: state.db.execute('SELECT COUNT(*) FROM '+name+' WHERE scope=? AND channel=?', (scope, channel)).fetchone()[0]
              for name in ('imports', 'history_jobs', 'history_skips')}
    pending = state.db.execute("SELECT COUNT(*) FROM imports WHERE scope=? AND channel=? AND phase='uploading'", (scope, channel)).fetchone()[0]
    print(json.dumps({'mode': 'reset_preview', 'channel': channel, 'counts': counts, 'uncertain': pending}), flush=True)
    if not apply:
        return
    if pending:
        raise ImportFailure('存在发送结果不确定的记录，拒绝重置；先人工核对正在发送的任务')
    backup = root / ('imports-before-reset-' + str(time.time_ns()) + '.sqlite')
    with sqlite3.connect(backup) as target:
        state.db.backup(target)
    with state.db:
        for name in counts:
            state.db.execute('DELETE FROM '+name+' WHERE scope=? AND channel=?', (scope, channel))
    print(json.dumps({'mode': 'reset_completed', 'backup': backup.name, 'session_preserved': True}), flush=True)


async def run(args, root):
    from telethon import TelegramClient
    env = os.environ
    try:
        api_id = int(env.get('TG_API_ID', '0'))
        api_hash = env.get('TG_API_HASH', '')
        if api_id < 1 or not api_hash:
            raise ValueError()
    except ValueError:
        raise ImportFailure('请在私有env填写TG_API_ID和TG_API_HASH') from None
    client = TelegramClient(str(root / 'user'), api_id, api_hash, flood_sleep_threshold=60)
    api = None
    try:
        await client.connect()
        if args.command == 'login':
            await client.start()
        elif not await client.is_user_authorized():
            raise ImportFailure('请先执行login，在SJC终端完成账号登录')
        if (await client.get_me()).bot:
            raise ImportFailure('采集需要用户账号，会话不能使用Bot登录')
        if args.command == 'login':
            print('用户账号登录成功；会话仅保存在私有state目录')
            return
        entity = await client.get_entity(channel_name(env.get('SOURCE_CHANNEL', 'botmzt')))
        if not getattr(entity, 'broadcast', False):
            raise ImportFailure('目标不是频道')
        if getattr(entity, 'noforwards', False):
            raise ImportFailure('频道开启内容保护，停止采集')
        if args.command in ('import', 'import-history'):
            if env.get('IMPORT_AUTHORIZED') != 'yes':
                raise ImportFailure('确认有使用授权后，设置IMPORT_AUTHORIZED=yes')
            api = Lsky(env)
        if args.command == 'reset':
            api = Lsky(env)
            reset_import_state(root, api.check(), entity.id, args.apply)
        elif args.command in ('plan', 'import-history'):
            await history(client, entity, args, root, api)
        else:
            await collect(client, entity, args, root, env, api)
    finally:
        if api:
            api.client.close()
        await client.disconnect()


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser()
    parser.add_argument('command', choices=['login', 'preview', 'import', 'plan', 'import-history', 'reset', 'status'])
    parser.add_argument('--limit', type=int, default=20)
    parser.add_argument('--scan', type=int, default=200)
    parser.add_argument('--max-bytes', type=int, default=25 * 1024**2)
    parser.add_argument('--download-kib', type=int, default=1024, help='Source download average KiB/s (64..102400)')
    parser.add_argument('--interval', type=float, default=30, help='Seconds between imported source posts (3..3600)')
    parser.add_argument('--max-posts', type=int, default=0, help='Pause history after this many posts; 0=all')
    parser.add_argument('--order', choices=['oldest', 'newest'], default='oldest', help='History direction, default oldest first')
    parser.add_argument('--until', type=int, default=0, help='Inclusive source message upper bound; 0=latest snapshot')
    parser.add_argument('--apply', action='store_true', help='Apply reset after creating a SQLite backup')
    args = parser.parse_args()
    if args.until < 0 or (args.apply and args.command != 'reset'):
        parser.error('until>=0；apply仅适用于reset')
    if not (64 <= args.download_kib <= 102400 and 3 <= args.interval <= 3600 and args.max_posts >= 0):
        parser.error('download-kib=64..102400，interval=3..3600，max-posts>=0')
    if not (1 <= args.limit <= 100 and args.limit <= args.scan <= 2000 and 1 <= args.max_bytes <= 50 * 1024**2):
        parser.error('limit=1..100，scan=limit..2000，max-bytes最多50MiB')
    root = Path(os.environ.get('STATE_DIR', '/state'))
    root.mkdir(mode=0o700, parents=True, exist_ok=True)
    if args.command == 'status':
        state = State(root / 'imports.sqlite')
        for row in state.db.execute('SELECT channel,data FROM history_jobs'):
            report_history({'channel': row[0], **json.loads(row[1])}, 'status')
        for row in state.db.execute('SELECT phase,COUNT(*) FROM imports GROUP BY phase'):
            print(json.dumps({'phase': row[0], 'photos': row[1]}))
        for row in state.db.execute('SELECT reason,COUNT(*) FROM history_skips GROUP BY reason'):
            print(json.dumps({'skip_reason': row[0], 'messages': row[1]}))
        state.db.close()
        return
    with (root / '.lock').open('a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise ImportFailure('已有登录或采集任务运行') from None
        # Only this tool's stale download directories; the exclusive lock excludes live jobs.
        for stale in root.glob('download-*'):
            if stale.is_dir() and not stale.is_symlink():
                shutil.rmtree(stale)
        asyncio.run(run(args, root))


if __name__ == '__main__':
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(130)
    except Exception as exc:
        # Telegram/HTTP exception messages can include account data or URLs. Do not dump traces.
        if type(exc).__name__ == 'FloodWaitError':
            print('Telegram限流：需等待' + str(getattr(exc, 'seconds', '未知')) + '秒；游标已保留，等待后用原命令继续', file=sys.stderr)
            sys.exit(1)
        print(str(exc) if isinstance(exc, ImportFailure) else '任务停止：' + type(exc).__name__ + '；请检查配置/网络，状态保留，不自动重复提交', file=sys.stderr)
        sys.exit(1)
