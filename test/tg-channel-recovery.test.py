"""Offline recovery checks: never delete production media or silently reset other work."""
import importlib.util
import io
import json
import hashlib
from contextlib import redirect_stdout
from pathlib import Path
import sqlite3
import tempfile
import unittest
from unittest.mock import patch

spec=importlib.util.spec_from_file_location('recovery',Path(__file__).resolve().parents[1]/'tools/tg-channel-import/recover-interleaved.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)


class RecoveryTests(unittest.TestCase):
    def fixture(self,base):
        lsky=base/'lsky';root=lsky/'storage/app/tg-photo-import';root.mkdir(parents=True)
        importer=base/'importer';state=importer/'state';state.mkdir(parents=True)
        (root/'user-1.lock').touch();(state/'.lock').touch();(state/'user.session').write_bytes(b'PRIVATE_SESSION')
        (lsky/'database').mkdir()
        with sqlite3.connect(lsky/'database/database.sqlite') as db:
            db.execute('CREATE TABLE images (key TEXT,user_id INTEGER,album_id INTEGER,strategy_id INTEGER,origin_name TEXT)')
            for group,n in m.GROUPS.items():
                db.execute('INSERT INTO images VALUES (?,?,?,?,?)',('K'+str(n),1,7,2,'mzt-'+str(n)+'.jpg'))
                data={'phase':'done','source_group':group,'result':{'images':[{'key':'K'+str(n),'source':{'name':'mzt-'+str(n)+'.jpg','bytes':10 if n==5771 else 20}}]}}
                (root/(hashlib.sha256(group.encode()).hexdigest()+'.json')).write_text(json.dumps(data))
        with sqlite3.connect(state/'imports.sqlite') as db:
            db.execute('CREATE TABLE imports(scope TEXT,channel INTEGER,message INTEGER,phase TEXT,sha256 TEXT,result TEXT)')
            db.execute('CREATE TABLE history_jobs(scope TEXT,channel INTEGER,data TEXT)')
            for n in (5770,5771,5772,5773):db.execute('INSERT INTO imports VALUES (?,?,?,?,?,?)',('scope',m.CHANNEL,n,'uploading' if n==5773 else 'done','sha',json.dumps({'key':'K'+str(n)})))
            stats={key:100 for key in ('posts','messages','eligible_photos','imported')};stats.update(source_bytes=10000,download_bytes=10000,reused=1)
            db.execute('INSERT INTO history_jobs VALUES (?,?,?)',('scope',m.CHANNEL,json.dumps({'order':'oldest','offset':5772,'completed':False,'stats':stats})))
        return lsky,importer,root

    def test_preview_is_read_only(self):
        with tempfile.TemporaryDirectory() as tmp:
            lsky,importer,root=self.fixture(Path(tmp));before=(importer/'state/imports.sqlite').read_bytes()
            with redirect_stdout(io.StringIO()):m.recover(lsky,importer)
            self.assertEqual(before,(importer/'state/imports.sqlite').read_bytes());self.assertEqual(len(list(root.glob('*.json'))),2)
            self.assertFalse(list(importer.glob('recovery-*')))

    def test_apply_preserves_media_session_and_links_and_only_rewinds_two_posts(self):
        with tempfile.TemporaryDirectory() as tmp:
            lsky,importer,root=self.fixture(Path(tmp));before=(lsky/'database/database.sqlite').read_bytes()
            with redirect_stdout(io.StringIO()):m.recover(lsky,importer,True)
            self.assertEqual(before,(lsky/'database/database.sqlite').read_bytes());self.assertEqual((importer/'state/user.session').read_bytes(),b'PRIVATE_SESSION')
            with sqlite3.connect(importer/'state/imports.sqlite') as db:
                self.assertEqual(db.execute('SELECT message FROM imports').fetchall(),[(5770,)])
                job=json.loads(db.execute('SELECT data FROM history_jobs').fetchone()[0]);self.assertEqual(job['offset'],5770)
                self.assertEqual(job['stats']['imported'],98);self.assertEqual(job['stats']['source_bytes'],9970);self.assertEqual(job['stats']['reused'],1)
            backups=list(importer.glob('recovery-interleaved-*'));self.assertEqual(len(backups),1)
            with sqlite3.connect(backups[0]/'imports.sqlite') as db:self.assertEqual(db.execute('SELECT COUNT(*) FROM imports').fetchone()[0],4)
            self.assertEqual(len(list(root.glob('*.json'))),0);self.assertEqual(len(list(root.glob('*.interleaved-repair-*'))),2)
            with self.assertRaises(RuntimeError):m.recover(lsky,importer,True)

    def test_refuses_other_uncertain_messages(self):
        with tempfile.TemporaryDirectory() as tmp:
            lsky,importer,root=self.fixture(Path(tmp))
            with sqlite3.connect(importer/'state/imports.sqlite') as db:db.execute('UPDATE imports SET message=9999 WHERE message=5773')
            with self.assertRaises(RuntimeError):m.recover(lsky,importer,True)
            self.assertEqual(len(list(root.glob('*.json'))),2);self.assertFalse(list(importer.glob('recovery-*')))

    def test_refuses_already_registered_missing_member(self):
        with tempfile.TemporaryDirectory() as tmp:
            lsky,importer,root=self.fixture(Path(tmp))
            with sqlite3.connect(lsky/'database/database.sqlite') as db:db.execute('INSERT INTO images VALUES (?,?,?,?,?)',('new',1,7,2,'mzt-5773.jpg'))
            with self.assertRaises(RuntimeError):m.recover(lsky,importer,True)
            self.assertEqual(len(list(root.glob('*.json'))),2)

    def test_failed_second_rename_rolls_back_collector_and_restores_ledgers(self):
        with tempfile.TemporaryDirectory() as tmp:
            lsky,importer,root=self.fixture(Path(tmp));original=Path.rename;calls=0
            def fail_once(path,target):
                nonlocal calls
                calls+=1
                if calls==2:raise OSError('disk problem')
                return original(path,target)
            with patch.object(Path,'rename',fail_once),self.assertRaises(OSError),redirect_stdout(io.StringIO()):m.recover(lsky,importer,True)
            with sqlite3.connect(importer/'state/imports.sqlite') as db:self.assertEqual(db.execute('SELECT COUNT(*) FROM imports').fetchone()[0],4)
            self.assertEqual(len(list(root.glob('*.json'))),2)


if __name__=='__main__':unittest.main()
