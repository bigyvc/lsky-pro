#!/usr/bin/env python3
"""Preview/recover only the operator-confirmed absent source post 12758..12766.

No source/TG/media deletion, no cursor rewind, no automatic network send.
"""
import argparse
from contextlib import ExitStack
import fcntl
import json
from pathlib import Path
import re
import shutil
import sqlite3
import subprocess
import time

CHANNEL=1109579085
GROUP='1109579085:g13352363222278213'
MESSAGES=list(range(12758,12767))


def recover(lsky=Path('/opt/lsky/data'),importer=Path('/opt/tg-import'),apply=False):
    state=importer/'state';root=lsky/'storage/app/tg-photo-import'
    with ExitStack() as stack:
        locks=[state/'.lock']+sorted(root.glob('user-*.lock'))
        if len(locks)<2:raise RuntimeError('缺少导入锁，停止')
        for path in locks:
            handle=stack.enter_context(path.open('r+'));fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
        candidates=[]
        for path in root.glob('*.json'):
            if not re.fullmatch(r'[a-f0-9]{64}\.json',path.name):continue
            data=json.loads(path.read_text())
            if data.get('source_group')==GROUP:candidates.append((path,data))
        if len(candidates)!=1:raise RuntimeError('目标组登记不存在或不唯一，停止')
        path,data=candidates[0]
        if data.get('phase')!='sending' or data.get('message_ids') or data.get('result'):
            raise RuntimeError('状态已变化或已有发送结果，停止')
        keys=data.get('keys',[])
        if len(keys)!=9 or len(set(keys))!=9:raise RuntimeError('目标编码数量不符，停止')
        db=sqlite3.connect((state/'imports.sqlite').as_uri()+'?mode=rw',uri=True,timeout=15);stack.callback(db.close)
        rows=db.execute("SELECT scope,channel,message FROM imports WHERE phase='uploading' ORDER BY message").fetchall()
        if len(rows)!=9 or [r[2] for r in rows]!=MESSAGES:raise RuntimeError('待确认上传不是12758～12766，停止')
        scope=rows[0][0]
        if any(r[:2]!=(scope,CHANNEL) for r in rows):raise RuntimeError('采集归属不符，停止')
        row=db.execute('SELECT data FROM history_jobs WHERE scope=? AND channel=?',(scope,CHANNEL)).fetchone()
        job=json.loads(row[0]) if row else {}
        if job.get('offset')!=12753 or job.get('completed') or job.get('group_cursor')!='complete-v2' or job.get('order')!='oldest':
            raise RuntimeError('游标或任务版本不是本次故障状态，停止')
        members=[r[0] for r in db.execute('SELECT message FROM source_messages WHERE scope=? AND channel=? AND snapshot=? AND post=? ORDER BY message',(scope,CHANNEL,job['snapshot'],GROUP.split(':')[1]))]
        if members!=MESSAGES:raise RuntimeError('完整源组索引不匹配，停止')
        previous=db.execute('SELECT phase,result FROM imports WHERE scope=? AND channel=? AND message=12753',(scope,CHANNEL)).fetchone()
        if not previous or previous[0]!='done':raise RuntimeError('上一组尚未完成，停止')
        previous_key=json.loads(previous[1] or '{}').get('key')
        prod=sqlite3.connect((lsky/'database/database.sqlite').as_uri()+'?mode=ro',uri=True);stack.callback(prod.close)
        if prod.execute('SELECT COUNT(*) FROM images WHERE key IN (?,?,?,?,?,?,?,?,?)',keys).fetchone()[0]:
            raise RuntimeError('待确认组已经有图床记录，停止')
        owner=prod.execute('SELECT user_id,album_id,origin_name FROM images WHERE key=?',(previous_key,)).fetchone()
        if not owner or not owner[0] or not owner[1] or owner[2]!='mzt-12753.jpg':raise RuntimeError('上一组图床归属无法核对，停止')
        names=['mzt-'+str(n)+'.jpg' for n in MESSAGES]
        if prod.execute('SELECT COUNT(*) FROM images WHERE user_id=? AND album_id=? AND origin_name IN (?,?,?,?,?,?,?,?,?)',(*owner[:2],*names)).fetchone()[0]:
            raise RuntimeError('该相册已有目标文件名，请人工核对，停止')
        report={'mode':'retry_preview','messages':MESSAGES,'source_group':GROUP,'offset_unchanged':12753,'files_deleted':0}
        print(json.dumps(report,ensure_ascii=False),flush=True)
        if not apply:return report
        backup=importer/('recovery-unsent-12758-'+str(time.time_ns()));backup.mkdir(mode=0o700)
        with sqlite3.connect(backup/'imports.sqlite') as target:db.backup(target)
        shutil.copy2(path,backup/path.name)
        retired=path.with_name(path.name+'.confirmed-unsent-'+backup.name)
        try:
            db.execute('BEGIN IMMEDIATE');path.rename(retired)
            count=db.execute("DELETE FROM imports WHERE scope=? AND channel=? AND phase='uploading' AND message IN (12758,12759,12760,12761,12762,12763,12764,12765,12766)",(scope,CHANNEL)).rowcount
            if count!=9:raise RuntimeError('待确认记录发生变化，停止')
            db.commit()
        except BaseException:
            db.rollback()
            if retired.exists() and not path.exists():retired.rename(path)
            raise
        print(json.dumps({'mode':'retry_ready','backup':str(backup),'offset_unchanged':12753,'files_deleted':0},ensure_ascii=False),flush=True)
        return report


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--apply',action='store_true');args=parser.parse_args()
    try:
        if subprocess.check_output(['docker','inspect','--format','{{.State.Running}}','tg-import-oldest-fast'],text=True).strip()!='false':
            raise RuntimeError('采集器仍在运行，停止')
        recover(apply=args.apply)
    except Exception as exc:
        print('恢复停止：'+(str(exc) if isinstance(exc,RuntimeError) else type(exc).__name__))
        raise SystemExit(1)
