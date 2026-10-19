import importlib.util
import io
import json
import hashlib
from contextlib import redirect_stdout
from pathlib import Path
import sqlite3
import tempfile
import unittest

spec=importlib.util.spec_from_file_location('recovery',Path(__file__).resolve().parents[1]/'tools/tg-channel-import/recover-rate-limit-15395.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)


class Tests(unittest.TestCase):
    def fixture(self,base):
        lsky=base/'lsky';root=lsky/'storage/app/tg-photo-import';root.mkdir(parents=True)
        importer=base/'importer';state=importer/'state';state.mkdir(parents=True)
        (root/'user-1.lock').touch();(state/'.lock').touch();(state/'user.session').write_bytes(b'SESSION')
        keys=['BlQF1R','UQdFJK','WCC3C9','J8kxOg']
        (root/(hashlib.sha256(m.GROUP.encode()).hexdigest()+'.json')).write_text(json.dumps({'phase':'sending','source_group':m.GROUP,'keys':keys}))
        import os
        from datetime import datetime
        from zoneinfo import ZoneInfo
        stamp=datetime(2026,10,19,23,21,7,tzinfo=ZoneInfo('Asia/Shanghai')).timestamp()
        os.utime(next(root.glob('*.json')),(stamp,stamp))
        logs=lsky/'storage/logs';logs.mkdir(parents=True)
        (logs/'laravel-2026-10-19.log').write_text('[2026-10-19 23:21:07] prod.ERROR: Telegram Bot API请求失败 [sendMediaGroup]：HTTP=429 TG=429 curl=0 category=RATE_LIMIT retry_after=18s\n')
        (lsky/'database').mkdir()
        with sqlite3.connect(lsky/'database/database.sqlite') as db:
            db.execute('CREATE TABLE images(key TEXT,user_id INTEGER,album_id INTEGER,origin_name TEXT)')
            db.execute('INSERT INTO images VALUES (?,?,?,?)',('OLD',1,7,'mzt-15390.jpg'))
        with sqlite3.connect(state/'imports.sqlite') as db:
            db.execute('CREATE TABLE imports(scope TEXT,channel INTEGER,message INTEGER,phase TEXT,result TEXT)')
            db.execute('CREATE TABLE history_jobs(scope TEXT,channel INTEGER,data TEXT)')
            db.execute('CREATE TABLE source_messages(scope TEXT,channel INTEGER,snapshot INTEGER,message INTEGER,post TEXT)')
            for n in m.MESSAGES:
                db.execute('INSERT INTO imports VALUES (?,?,?,?,?)',('scope',m.CHANNEL,n,'uploading',None))
                db.execute('INSERT INTO source_messages VALUES (?,?,?,?,?)',('scope',m.CHANNEL,22569,n,m.GROUP.split(':')[1]))
            db.execute('INSERT INTO imports VALUES (?,?,?,?,?)',('scope',m.CHANNEL,15390,'done',json.dumps({'key':'OLD'})))
            db.execute('INSERT INTO history_jobs VALUES (?,?,?)',('scope',m.CHANNEL,json.dumps({'offset':15390,'order':'oldest','completed':False,'snapshot':22569,'group_cursor':'complete-v2','stats':{'imported':14371}})))
        return lsky,importer,root

    def test_preview_does_not_mutate(self):
        with tempfile.TemporaryDirectory() as tmp:
            lsky,importer,root=self.fixture(Path(tmp));before=(importer/'state/imports.sqlite').read_bytes()
            with redirect_stdout(io.StringIO()):m.recover(lsky,importer)
            self.assertEqual(before,(importer/'state/imports.sqlite').read_bytes());self.assertEqual(len(list(root.glob('*.json'))),1)

    def test_apply_preserves_cursor_stats_production_and_previous_success(self):
        with tempfile.TemporaryDirectory() as tmp:
            lsky,importer,root=self.fixture(Path(tmp));before=(lsky/'database/database.sqlite').read_bytes()
            with sqlite3.connect(importer/'state/imports.sqlite') as db:job=db.execute('SELECT data FROM history_jobs').fetchone()[0]
            with redirect_stdout(io.StringIO()):m.recover(lsky,importer,True)
            with sqlite3.connect(importer/'state/imports.sqlite') as db:
                self.assertEqual(db.execute('SELECT data FROM history_jobs').fetchone()[0],job)
                self.assertEqual(db.execute('SELECT message,phase FROM imports').fetchall(),[(15390,'done')])
                self.assertEqual(db.execute('SELECT COUNT(*) FROM source_messages').fetchone()[0],4)
            self.assertEqual(before,(lsky/'database/database.sqlite').read_bytes());self.assertEqual((importer/'state/user.session').read_bytes(),b'SESSION')
            self.assertEqual(len(list(importer.glob('recovery-rate-limit-*'))),1);self.assertEqual(len(list(root.glob('*.json'))),0)

    def test_refuses_new_result_or_wrong_group_members(self):
        for kind in ('sent','index','record','evidence'):
            with self.subTest(kind=kind),tempfile.TemporaryDirectory() as tmp:
                lsky,importer,root=self.fixture(Path(tmp))
                if kind=='sent':
                    p=next(root.glob('*.json'));data=json.loads(p.read_text());data['message_ids']=[999];p.write_text(json.dumps(data))
                elif kind=='index':
                    with sqlite3.connect(importer/'state/imports.sqlite') as db:db.execute('UPDATE source_messages SET post=? WHERE message=15395',('g999',))
                elif kind=='evidence':
                    (lsky/'storage/logs/laravel-2026-10-19.log').write_text('unrelated HTTP429')
                else:
                    with sqlite3.connect(lsky/'database/database.sqlite') as db:db.execute('INSERT INTO images VALUES (?,?,?,?)',('BlQF1R',1,7,'mzt-15395.jpg'))
                with self.assertRaises(RuntimeError):m.recover(lsky,importer,True)
                self.assertEqual(len(list(root.glob('*.json'))),1);self.assertFalse(list(importer.glob('recovery-rate-limit-*')))


if __name__=='__main__':unittest.main()
