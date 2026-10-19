#!/usr/bin/env python3
"""Narrow, backed-up recovery for confirmed interleaved botmzt posts 5771..5774.

Preserves all existing Lsky/TG files and original links. No Bot API calls. Default preview.
"""
import argparse
import fcntl
import json
from pathlib import Path
import re
import shutil
import sqlite3
import subprocess
import time
from contextlib import ExitStack

CHANNEL = 1109579085
GROUPS = {
    '1109579085:g12917568124999561': 5771,
    '1109579085:g12917568129407497': 5772,
}


def recover(lsky=Path('/opt/lsky/data'), importer=Path('/opt/tg-import'), apply=False):
    state=importer/'state';root=lsky/'storage/app/tg-photo-import'
    with ExitStack() as stack:
        locks=[state/'.lock']+sorted(root.glob('user-*.lock'))
        if len(locks)<2: raise RuntimeError('缺少导入锁，停止')
        for path in locks:
            if not path.is_file(): raise RuntimeError('锁文件不存在，停止')
            handle=stack.enter_context(path.open('r+'))
            fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
        found={}
        for path in root.glob('*.json'):
            if not re.fullmatch(r'[a-f0-9]{64}\.json',path.name): continue
            data=json.loads(path.read_text())
            group=data.get('source_group')
            if group in GROUPS:
                if group in found: raise RuntimeError('登记重复，停止')
                found[group]=(path,data)
        if set(found)!=set(GROUPS): raise RuntimeError('找不到两条指定登记，停止')
        sources={};keys={};source_bytes=0
        for group,message in GROUPS.items():
            path,data=found[group];images=(data.get('result') or {}).get('images',[])
            if data.get('phase')!='done' or len(images)!=1: raise RuntimeError('不是已知的单张完成登记，停止')
            image=images[0];source=image.get('source',{})
            if source.get('name')!='mzt-'+str(message)+'.jpg' or not image.get('key') or int(source.get('bytes',0))<1:
                raise RuntimeError('源文件名、大小或返回编码不符，停止')
            sources[message]=image;keys[message]=image['key'];source_bytes+=int(source['bytes'])
        prod=sqlite3.connect((lsky/'database/database.sqlite').as_uri()+'?mode=ro',uri=True)
        stack.callback(prod.close)
        owners=[]
        for message,key in keys.items():
            row=prod.execute('SELECT user_id,album_id,strategy_id,origin_name FROM images WHERE key=?',(key,)).fetchone()
            if not row or row[3]!='mzt-'+str(message)+'.jpg': raise RuntimeError('旧图床记录不匹配，停止')
            owners.append(row[:3])
        if owners[0]!=owners[1] or not all(owners[0]): raise RuntimeError('旧记录归属不一致，停止')
        if prod.execute('SELECT COUNT(*) FROM images WHERE user_id=? AND album_id=? AND origin_name IN (?,?)',
            (owners[0][0],owners[0][1],'mzt-5773.jpg','mzt-5774.jpg')).fetchone()[0]:
            raise RuntimeError('缺失成员已经有图床记录，需人工核对，停止')
        db=sqlite3.connect((state/'imports.sqlite').as_uri()+'?mode=rw',uri=True,timeout=15)
        stack.callback(db.close)
        pending=db.execute("SELECT scope,channel,message FROM imports WHERE phase='uploading'").fetchall()
        if len(pending)!=1 or pending[0][1:]!=(CHANNEL,5773): raise RuntimeError('待确认记录并非仅5773，停止')
        scope=pending[0][0]
        for message,key in keys.items():
            row=db.execute('SELECT phase,result FROM imports WHERE scope=? AND channel=? AND message=?',(scope,CHANNEL,message)).fetchone()
            if not row or row[0]!='done' or json.loads(row[1] or '{}').get('key')!=key:
                raise RuntimeError('采集器完成记录与图床不符，停止')
        if db.execute('SELECT 1 FROM imports WHERE scope=? AND channel=? AND message=5774',(scope,CHANNEL)).fetchone():
            raise RuntimeError('5774已存在采集状态，停止')
        row=db.execute('SELECT data FROM history_jobs WHERE scope=? AND channel=?',(scope,CHANNEL)).fetchone()
        job=json.loads(row[0]) if row else {}
        if job.get('offset')!=5772 or job.get('order')!='oldest' or job.get('completed') or job.get('group_cursor')=='complete-v2':
            raise RuntimeError('不是此次故障的旧游标5772，停止')
        stats=job['stats'];changes={name:2 for name in ('posts','messages','eligible_photos','imported')}
        changes.update(source_bytes=source_bytes,download_bytes=source_bytes)
        for name,count in changes.items():
            if stats.get(name,0)<count: raise RuntimeError('统计值不足，停止')
        report={'mode':'repair_preview','old_offset':5772,'new_offset':5770,
            'replay_groups':[[5771,5773],[5772,5774]],'preserved_original_keys':list(keys.values()),
            'note':'旧图床记录、原链接和TG消息全部保留；新版重传两组完整照片后图库会采用完整组'}
        print(json.dumps(report,ensure_ascii=False),flush=True)
        if not apply: return report
        backup=importer/('recovery-interleaved-'+str(time.time_ns()));backup.mkdir(mode=0o700)
        with sqlite3.connect(backup/'imports.sqlite') as target: db.backup(target)
        for path,_ in found.values(): shutil.copy2(path,backup/path.name)
        (backup/'repair.json').write_text(json.dumps(report,ensure_ascii=False))
        renamed=[]
        try:
            db.execute('BEGIN IMMEDIATE')
            for path,_ in found.values():
                retired=path.with_name(path.name+'.interleaved-repair-'+backup.name)
                path.rename(retired);renamed.append((path,retired))
            removed=db.execute('DELETE FROM imports WHERE scope=? AND channel=? AND message IN (5771,5772,5773)',(scope,CHANNEL)).rowcount
            if removed!=3: raise RuntimeError('待恢复记录发生变化，停止')
            job['offset']=5770;job['updated_at']=int(time.time());job.pop('group_cursor',None)
            for name,count in changes.items(): stats[name]-=count
            db.execute('UPDATE history_jobs SET data=? WHERE scope=? AND channel=?',(json.dumps(job),scope,CHANNEL))
            db.commit()
        except BaseException:
            db.rollback()
            for original,retired in reversed(renamed):
                if retired.exists() and not original.exists(): retired.rename(original)
            raise
        print(json.dumps({'mode':'repair_complete','backup':str(backup),'offset':5770,'original_files_deleted':0},ensure_ascii=False),flush=True)
        return report


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--apply',action='store_true')
    args=parser.parse_args()
    try:
        running=subprocess.check_output(['docker','inspect','--format','{{.State.Running}}','tg-import-oldest-fast'],text=True).strip()
        if running!='false': raise RuntimeError('请先停止采集器，未修改状态')
        recover(apply=args.apply)
    except Exception as exc:
        print('恢复未执行或已回滚：'+(str(exc) if isinstance(exc,RuntimeError) else type(exc).__name__))
        raise SystemExit(1)
