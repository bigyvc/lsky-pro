"""Optional user-session collector. Never touches the Lsky database or Bot API directly."""
import argparse
import asyncio
import fcntl
import hashlib
import json
import mimetypes
import os
from pathlib import Path
import shutil
import sqlite3
import sys
import tempfile
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

    def upload(self, path, filename, hashes):
        with path.open('rb') as f:
            data = api_data(self.client.post(self.origin + '/api/v1/upload',
                data={'strategy_id': str(self.strategy), 'album_id': str(self.album_id)},
                files={'file': (filename, f, mimetypes.guess_type(filename)[0] or 'application/octet-stream')}))
        if not isinstance(data, dict) or not data.get('key') or not data.get('links', {}).get('url'):
            raise ImportFailure('上传回包缺少文件记录')
        if data.get('album_id') != self.album_id:
            raise ImportFailure('上传回包相册不符，请核对记录，不自动重试')
        if data.get('md5') != hashes['md5'] or data.get('sha1') != hashes['sha1']:
            raise ImportFailure('图床内容哈希不符，请关闭导入角色图片转换、水印和重编码')
        return {'key': data['key'], 'url': data['links']['url']}


class State:
    def __init__(self, path):
        self.db = sqlite3.connect(path)
        self.db.execute('''CREATE TABLE IF NOT EXISTS imports (
            scope TEXT, channel INTEGER, message INTEGER, phase TEXT, sha256 TEXT,
            result TEXT, PRIMARY KEY(scope,channel,message))''')
        self.db.commit()

    def get(self, scope, channel, message):
        return self.db.execute('SELECT phase,result FROM imports WHERE scope=? AND channel=? AND message=?',
                               (scope, channel, message)).fetchone()

    def duplicate(self, scope, sha):
        return self.db.execute("SELECT result FROM imports WHERE scope=? AND sha256=? AND phase='done' LIMIT 1", (scope, sha)).fetchone()

    def save(self, scope, channel, message, phase, sha, result=None):
        self.db.execute('INSERT OR REPLACE INTO imports VALUES (?,?,?,?,?,?)',
                        (scope, channel, message, phase, sha, json.dumps(result) if result else None))
        self.db.commit()


def extension(msg):
    if getattr(msg, 'photo', None):
        return '.jpg'
    f = getattr(msg, 'file', None)
    ext = (getattr(f, 'ext', '') or '').lower()
    return ext if getattr(msg, 'document', None) and ext in ('.jpg', '.jpeg', '.png', '.webp', '.gif') else None


async def collect(client, entity, args, root, env, api=None):
    state = State(root / 'imports.sqlite') if api else None
    scope = api.check() if api else None
    count = 0
    async for msg in client.iter_messages(entity, limit=args.scan):
        ext = extension(msg)
        if not ext or getattr(msg, 'noforwards', False):
            continue
        count += 1
        size = int(getattr(msg.file, 'size', 0) or 0)
        print(json.dumps({'message': msg.id, 'bytes': size, 'action': args.command}), flush=True)
        if api:
            old = state.get(scope, entity.id, msg.id)
            if old and old[0] == 'uploading':
                raise ImportFailure('存在结果不确定的上传，请先在图床核对，勿自动重复提交：消息' + str(msg.id))
            if old and old[0] == 'done':
                print('已导入，跳过', flush=True)
            elif size < 1 or size > args.max_bytes:
                print('跳过：大小未知或超过单文件限额', flush=True)
            else:
                if shutil.disk_usage(root).free < size + 2 * 1024**3:
                    raise ImportFailure('剩余磁盘不足，至少保留2GiB')
                with tempfile.TemporaryDirectory(prefix='download-', dir=root) as temp:
                    path = Path(temp) / ('image' + ext)
                    await client.download_media(msg, file=str(path))
                    if not path.is_file() or path.stat().st_size < 1 or path.stat().st_size > args.max_bytes:
                        raise ImportFailure('下载文件为空或超过单文件限额')
                    hashes = digest(path)
                    previous = state.duplicate(scope, hashes['sha256'])
                    if previous:
                        result = json.loads(previous[0])
                    else:
                        filename = 'tg_' + str(entity.id) + '_' + str(msg.id) + ext
                        state.save(scope, entity.id, msg.id, 'uploading', hashes['sha256'])
                        # Do not retry POST automatically: a lost response may hide a successful upload.
                        result = await asyncio.to_thread(api.upload, path, filename, hashes)
                    state.save(scope, entity.id, msg.id, 'done', hashes['sha256'], result)
                    print(json.dumps({'imported': msg.id, 'key': result['key']}, ensure_ascii=False), flush=True)
                await asyncio.sleep(3)
        if count >= args.limit:
            break
    print(json.dumps({'selected': count, 'mode': args.command}), flush=True)


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
        if args.command == 'import':
            if env.get('IMPORT_AUTHORIZED') != 'yes':
                raise ImportFailure('确认有使用授权后，设置IMPORT_AUTHORIZED=yes')
            api = Lsky(env)
        await collect(client, entity, args, root, env, api)
    finally:
        if api:
            api.client.close()
        await client.disconnect()


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser()
    parser.add_argument('command', choices=['login', 'preview', 'import', 'status'])
    parser.add_argument('--limit', type=int, default=20)
    parser.add_argument('--scan', type=int, default=200)
    parser.add_argument('--max-bytes', type=int, default=25 * 1024**2)
    args = parser.parse_args()
    if not (1 <= args.limit <= 100 and args.limit <= args.scan <= 2000 and 1 <= args.max_bytes <= 50 * 1024**2):
        parser.error('limit=1..100，scan=limit..2000，max-bytes最多50MiB')
    root = Path(os.environ.get('STATE_DIR', '/state'))
    root.mkdir(mode=0o700, parents=True, exist_ok=True)
    with (root / '.lock').open('a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise ImportFailure('已有登录或采集任务运行') from None
        # Only this tool's stale download directories; the exclusive lock excludes live jobs.
        for stale in root.glob('download-*'):
            if stale.is_dir() and not stale.is_symlink():
                shutil.rmtree(stale)
        if args.command == 'status':
            state = State(root / 'imports.sqlite')
            for row in state.db.execute('SELECT channel,message,phase FROM imports ORDER BY rowid DESC LIMIT 100'):
                print(json.dumps(dict(zip(['channel', 'message', 'phase'], row))))
        else:
            asyncio.run(run(args, root))


if __name__ == '__main__':
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(130)
    except Exception as exc:
        # Telegram/HTTP exception messages can include account data or URLs. Do not dump traces.
        print(str(exc) if isinstance(exc, ImportFailure) else '任务停止：' + type(exc).__name__ + '；请检查配置/网络，状态保留，不自动重复提交', file=sys.stderr)
        sys.exit(1)
