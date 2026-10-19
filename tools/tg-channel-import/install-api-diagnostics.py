#!/usr/bin/env python3
"""Install only a checksum-guarded TelegramAdapter hotfix; preserve the old source."""
from pathlib import Path
from contextlib import ExitStack
import fcntl
import hashlib
import os
import shutil
import subprocess
import time


def install():
    source=Path(__file__).resolve().parents[2]/'src/app/Filesystem/TelegramAdapter.php'
    target=Path('/opt/lsky/data/app/Filesystem/TelegramAdapter.php')
    if subprocess.check_output(['docker','inspect','--format','{{.State.Running}}','tg-import-oldest-fast'],text=True).strip()!='false':
        raise RuntimeError('采集器仍在运行，停止安装')
    new=source.read_bytes();old=target.read_bytes()
    if old==new:
        print('诊断补丁已经安装');return
    if hashlib.md5(old).hexdigest()!='28996cb81484fcc29f34ecafb1d1ac4b':
        raise RuntimeError('服务器适配器不是已核对版本，未覆盖；请先核对本地修改')
    with ExitStack() as stack:
        locks=[Path('/opt/tg-import/state/.lock')]+sorted(Path('/opt/lsky/data/storage/app/tg-photo-import').glob('user-*.lock'))
        if len(locks)<2:raise RuntimeError('找不到导入锁，停止')
        for lock in locks:
            handle=stack.enter_context(lock.open('r+'));fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
        stamp=str(time.time_ns());remote='/tmp/tg-api-diagnostic-'+stamp+'.php'
        subprocess.run(['docker','cp',str(source),'lsky-pro:'+remote],check=True)
        try:subprocess.run(['docker','exec','lsky-pro','php','-l',remote],check=True)
        finally:subprocess.run(['docker','exec','lsky-pro','rm','-f',remote],check=True)
        backup=Path('/opt/tg-import')/('api-diagnostics-backup-'+stamp);backup.mkdir(mode=0o700)
        shutil.copy2(target,backup/'TelegramAdapter.php')
        stat=target.stat();temp=target.with_name('.TelegramAdapter-'+stamp+'.tmp')
        try:
            temp.write_bytes(new);os.chown(temp,stat.st_uid,stat.st_gid);os.chmod(temp,stat.st_mode&0o777)
            os.replace(temp,target)
            subprocess.run(['docker','exec','lsky-pro','apachectl','-k','graceful'],check=True)
        except BaseException:
            shutil.copy2(backup/'TelegramAdapter.php',target)
            subprocess.run(['docker','exec','lsky-pro','apachectl','-k','graceful'])
            raise
        finally:
            if temp.exists():temp.unlink()
        print('诊断补丁已安装；原文件备份：'+str(backup))


if __name__=='__main__':
    try:install()
    except Exception as exc:
        print('安装停止：'+(str(exc) if isinstance(exc,RuntimeError) else type(exc).__name__))
        raise SystemExit(1)
