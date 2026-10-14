#!/usr/bin/env python3
"""Only evict completed getFile media explicitly registered by Lsky. Never scan TDLib state."""
import argparse, fcntl, json, os, stat, time
from pathlib import Path

MEDIA = {'documents','photos','videos','animations','audio','voice','video_notes','stickers'}

def clean(registry, data, container_root='/var/lib/telegram-bot-api', ttl=3600,
          max_bytes=2*1024**3, min_free=2*1024**3, min_age=300, dry_run=False):
    registry, data = Path(registry), Path(data).resolve(strict=True)
    if not registry.is_dir(): return {'registered':0,'removed':0,'freed':0,'busy':False}
    result = {'registered':0,'removed':0,'freed':0,'busy':False}
    with open(registry/'.lock', 'a') as lock:
        try: fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError: result['busy']=True; return result
        entries=[]
        for manifest in registry.glob('*.json'):
            if manifest.is_symlink(): continue
            try:
                item=json.loads(manifest.read_text())
                rel=Path(item['relative'])
                if item['root'] != container_root or rel.is_absolute() or '..' in rel.parts or rel.parent.name not in MEDIA: continue
                path=data/rel
                # Reject symlinks in every component and all files outside the configured root.
                current=data
                for part in rel.parts:
                    current=current/part
                    if current.is_symlink(): raise ValueError('symlink')
                info=path.stat()
                if not stat.S_ISREG(info.st_mode): continue
                if (info.st_dev, info.st_ino, info.st_size, int(info.st_mtime)) != (item['device'], item['inode'], item['size'], item['mtime']): continue
                entries.append((item['accessed'], info.st_size, path, manifest, info))
            except FileNotFoundError:
                if not dry_run: manifest.unlink(missing_ok=True)
            except (ValueError, KeyError, TypeError, OSError): continue
        entries.sort(key=lambda e:e[0]); total=sum(e[1] for e in entries)
        disk=os.statvfs(data); free=disk.f_bavail*disk.f_frsize; now=time.time()
        result['registered']=len(entries)
        for accessed,size,path,manifest,info in entries:
            age=now-accessed
            if age < min_age or (age < ttl and total <= max_bytes and free >= min_free): continue
            try:
                # Shared flock is held by PHP until stream close; do not evict active reads.
                fd=os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
                with os.fdopen(fd, 'rb') as stream:
                    try: fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    except BlockingIOError: continue
                    current=os.fstat(stream.fileno())
                    actual=path.stat()
                    if (current.st_dev,current.st_ino,current.st_size,current.st_mtime_ns) != (info.st_dev,info.st_ino,info.st_size,info.st_mtime_ns): continue
                    if (actual.st_dev,actual.st_ino) != (current.st_dev,current.st_ino): continue
                    if not dry_run: path.unlink(); manifest.unlink(missing_ok=True)
                    result['removed']+=1; result['freed']+=size; total-=size; free+=size
            except OSError: continue
        result['dry_run']=dry_run
    return result

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--registry',default='/opt/lsky/data/storage/app/tg-cache')
    p.add_argument('--data',default='/opt/lsky/telegram-api/data')
    p.add_argument('--container-root',default='/var/lib/telegram-bot-api')
    p.add_argument('--ttl',type=int,default=3600)
    p.add_argument('--max-bytes',type=int,default=2*1024**3)
    p.add_argument('--min-free',type=int,default=2*1024**3)
    p.add_argument('--min-age',type=int,default=300)
    p.add_argument('--dry-run',action='store_true')
    a=p.parse_args()
    if min(a.ttl,a.max_bytes,a.min_free)<0 or a.min_age<300: p.error('thresholds must be nonnegative; minimum age must be >=300 seconds')
    print(json.dumps(clean(a.registry,a.data,a.container_root,a.ttl,a.max_bytes,a.min_free,a.min_age,a.dry_run)))
if __name__=='__main__': main()
