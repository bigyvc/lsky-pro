#!/usr/bin/env python3
"""Conservative host watchdog. Never modifies import/production records or sends media."""
import argparse,datetime,fcntl,json,os,re,sqlite3,subprocess,time
from pathlib import Path


def command(args):
    return subprocess.check_output(args,text=True,stderr=subprocess.DEVNULL,timeout=45).strip()


def snapshot(root):
    path=root/'imports.sqlite'
    with sqlite3.connect(path.as_uri()+'?mode=ro',uri=True,timeout=5) as db:
        jobs=[json.loads(row[0]) for row in db.execute('SELECT data FROM history_jobs')]
        pending=db.execute("SELECT channel,message FROM imports WHERE phase='uploading' ORDER BY message").fetchall()
    if len(jobs)!=1:raise RuntimeError('需要唯一历史任务，未执行恢复')
    return jobs[0],pending


def diagnostic(lsky):
    # Return only fixed diagnostic tokens, never raw exception messages or URLs.
    files=sorted((lsky/'storage/logs').glob('laravel*.log'))[-2:]
    found=None
    for path in files:
        with path.open('rb') as f:
            f.seek(max(0,path.stat().st_size-131072))
            for line in f.read().decode(errors='replace').splitlines():
                match=re.search(r'Telegram Bot API请求失败 \[(sendMediaGroup|sendPhoto)\]：HTTP=(\d{1,3}) TG=(\d{1,6}) curl=(\d{1,3}) category=([A-Z_]+(?::item\d{1,2})?)(?: retry_after=(\d{1,10})s)?',line)
                if match:found={'method':match[1],'http':int(match[2]),'tg':int(match[3]),'curl':int(match[4]),'category':match[5],'retry_after':int(match[6] or 0)}
    return found


def decide(job,pending,running,exit_code,downloads,now,started,history,paused=False,competing=False):
    if paused:return 'paused'
    if competing:return 'another_collector'
    if job.get('completed'):return 'completed'
    if pending:return 'uploading' if running and now-int(job.get('updated_at',0))<900 else 'blocked_uncertain'
    if running:
        stale=now-int(job.get('updated_at',now))
        if downloads and now-started>=900 and stale>=900 and all(now-mtime>=900 for mtime in downloads):
            action='restart_stalled_download'
        elif not downloads and now-started>=1800 and stale>=1800:return 'stalled_unknown'
        else:return 'running'
    else:
        if exit_code!=1:return 'stopped_intentionally'
        action='restart_exited'
    recent=[t for t in history if now-t<3600]
    if len(recent)>=3:return 'restart_limit'
    if recent and now-max(recent)<300:return 'backoff'
    return action


def collector_lock(root):
    handle=(root/'.lock').open('r+')
    try:fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
    except BlockingIOError:handle.close();return None
    return handle


def check(args):
    now=time.time();out=args.monitor;out.mkdir(mode=0o700,parents=True,exist_ok=True)
    with (out/'.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        statefile=out/'monitor.json'
        old=json.loads(statefile.read_text()) if statefile.exists() else {}
        history=[t for t in old.get('restarts',[]) if now-t<3600]
        data=json.loads(command(['docker','inspect','--format','{{json .State}}',args.container]))
        running=bool(data['Running']);job,pending=snapshot(args.state)
        mounts=json.loads(command(['docker','inspect','--format','{{json .Mounts}}',args.container]))
        if not any(m['Destination']=='/state' and m['Type']=='bind' and Path(m['Source']).resolve()==args.state.resolve() for m in mounts):
            raise RuntimeError('采集器/state挂载不匹配，未恢复')
        started=datetime.datetime.fromisoformat(data['StartedAt'].replace('Z','+00:00')).timestamp()
        downloads=[p.stat().st_mtime for d in args.state.glob('download-*') if d.is_dir() and not d.is_symlink() for p in d.iterdir() if p.is_file()]
        handle=collector_lock(args.state)
        competing=not running and handle is None
        if handle:handle.close()
        action=decide(job,pending,running,data['ExitCode'],downloads,now,started,history,(out/'paused').exists(),competing)
        report={'time':int(now),'container':args.container,'status':action,'offset':job.get('offset'),'imported':job.get('stats',{}).get('imported'),'pending':[list(r) for r in pending],'restarts':history,'apply':args.apply}
        if pending:report['latest_available_diagnostic']=diagnostic(args.lsky)
        if args.apply and action.startswith('restart_') and action not in ('restart_limit',):
            if running:
                # A POST may begin after inspection. Stop gracefully, then recheck durable intents.
                command(['docker','stop','--time','30',args.container])
            gate=collector_lock(args.state)
            if gate is None:report['status']='another_collector'
            else:
                try:
                    job2,pending2=snapshot(args.state)
                    if pending2:report.update(status='blocked_uncertain',pending=[list(r) for r in pending2])
                    elif job2.get('completed'):report['status']='completed'
                    elif (out/'paused').exists():report['status']='paused'
                    else:
                        # Persist budget before start; failed Docker starts cannot loop every minute.
                        history.append(now);report['restarts']=history
                        interim=out/'.monitor.tmp';interim.write_text(json.dumps(report));os.replace(interim,statefile)
                        gate.close();gate=None
                        command(['docker','start',args.container]);report['status']='restarted'
                finally:
                    if gate:gate.close()
        temp=out/'.monitor.tmp';temp.write_text(json.dumps(report,ensure_ascii=False));os.chmod(temp,0o600);os.replace(temp,statefile)
        signature=(report['status'],report.get('pending'),report.get('latest_available_diagnostic'))
        previous=(old.get('status'),old.get('pending'),old.get('latest_available_diagnostic'))
        if signature!=previous or report['status']=='restarted':
            print(json.dumps(report,ensure_ascii=False),flush=True)
            with (out/'events.jsonl').open('a') as f:f.write(json.dumps(report,ensure_ascii=False)+'\n')
        elif not args.apply:print(json.dumps(report,ensure_ascii=False))


def main():
    os.umask(0o077)
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--container',default='tg-import-oldest-fast')
    parser.add_argument('--state',type=Path,default=Path('/opt/tg-import/state'))
    parser.add_argument('--lsky',type=Path,default=Path('/opt/lsky/data'))
    parser.add_argument('--monitor',type=Path,default=Path('/opt/tg-import/watchdog'))
    parser.add_argument('--apply',action='store_true')
    args=parser.parse_args()
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]+',args.container):raise RuntimeError('容器名称不合法')
    check(args)

if __name__=='__main__':
    try:main()
    except BlockingIOError:pass
    except Exception as exc:
        print(json.dumps({'status':'monitor_error','type':type(exc).__name__,'message':str(exc) if isinstance(exc,RuntimeError) else '检查监控配置与Docker/SQLite状态'},ensure_ascii=False),flush=True)
        raise SystemExit(1)
