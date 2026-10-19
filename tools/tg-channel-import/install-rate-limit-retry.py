"""Install two reviewed PHP files with backups and strict old-source checks."""
from pathlib import Path
from contextlib import ExitStack
import fcntl,hashlib,os,shutil,subprocess,time

FILES={'app/Filesystem/TelegramAdapter.php':'d9db4b9ab5b8656d9d2f042f4ae156d6','app/Exceptions/TelegramRateLimitException.php':None}

def main():
    source=Path(__file__).resolve().parents[2]/'src';target=Path('/opt/lsky/data')
    if subprocess.check_output(['docker','inspect','--format','{{.State.Running}}','tg-import-oldest-fast'],text=True).strip()!='false':raise RuntimeError('采集器仍在运行，停止')
    with ExitStack() as stack:
        locks=[Path('/opt/tg-import/state/.lock')]+sorted((target/'storage/app/tg-photo-import').glob('user-*.lock'))
        if len(locks)<2:raise RuntimeError('找不到导入锁')
        for p in locks:
            h=stack.enter_context(p.open('r+'));fcntl.flock(h,fcntl.LOCK_EX|fcntl.LOCK_NB)
        for name,expected in FILES.items():
            p=target/name;new=(source/name).read_bytes()
            if p.exists() and p.read_bytes()==new:continue
            if expected is None:
                if p.exists():raise RuntimeError('异常类已有本地修改，未覆盖')
            elif not p.exists() or hashlib.md5(p.read_bytes()).hexdigest() not in (expected,'28996cb81484fcc29f34ecafb1d1ac4b'):raise RuntimeError('适配器版本不是已核对的诊断版本，未覆盖')
        stamp=str(time.time_ns());backup=Path('/opt/tg-import')/('rate-limit-patch-backup-'+stamp);backup.mkdir(mode=0o700)
        originals={};changed=[]
        for name in FILES:
            p=target/name
            if p.exists():
                originals[name]=p.stat();dest=backup/name;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(p,dest)
            remote='/tmp/rate-limit-'+stamp+'.php'
            subprocess.run(['docker','cp',str(source/name),'lsky-pro:'+remote],check=True)
            try:subprocess.run(['docker','exec','lsky-pro','php','-l',remote],check=True)
            finally:subprocess.run(['docker','exec','lsky-pro','rm','-f',remote],check=True)
        try:
            # Install exception first; old adapter does not reference it.
            for name in reversed(FILES):
                p=target/name;temp=p.with_name('.rate-limit-'+stamp+'.tmp');temp.write_bytes((source/name).read_bytes())
                stat=originals.get(name) or (target/'app/Filesystem/TelegramAdapter.php').stat()
                os.chown(temp,stat.st_uid,stat.st_gid);os.chmod(temp,stat.st_mode&0o777);os.replace(temp,p);changed.append(name)
            subprocess.run(['docker','exec','lsky-pro','apachectl','-k','graceful'],check=True)
        except BaseException:
            for name in reversed(changed):
                if name in originals:shutil.copy2(backup/name,target/name)
                else:(target/name).unlink(missing_ok=True)
            subprocess.run(['docker','exec','lsky-pro','apachectl','-k','graceful'])
            raise
        print('限流重试补丁已安装，原文件备份：'+str(backup))

if __name__=='__main__':
    try:main()
    except Exception as exc:
        print('安装停止：'+(str(exc) if isinstance(exc,RuntimeError) else type(exc).__name__))
        raise SystemExit(1)
