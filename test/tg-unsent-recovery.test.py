import importlib.util
import io
import json
import hashlib
from contextlib import redirect_stdout
from pathlib import Path
import sqlite3
import tempfile
import unittest

spec=importlib.util.spec_from_file_location('recovery',Path(__file__).resolve().parents[1]/'tools/tg-channel-import/recover-unsent-12330.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)


class Tests(unittest.TestCase):
    def fixture(self,base):
        lsky=base/'lsky';root=lsky/'storage/app/tg-photo-import';root.mkdir(parents=True)
        importer=base/'importer';state=importer/'state';state.mkdir(parents=True)
        (root/'user-1.lock').touch();(state/'.lock').touch();(state/'user.session').write_bytes(b'SESSION')
        keys=['K'+str(n) for n in m.MESSAGES]
        (root/(hashlib.sha256(m.GROUP.encode()).hexdigest()+'.json')).write_text(json.dumps({'phase':'sending','source_group':m.GROUP,'keys':keys}))
        (lsky/'database').mkdir()
        with sqlite3.connect(lsky/'database/database.sqlite') as db:
            db.execute('CREATE TABLE images(key TEXT,user_id INTEGER,album_id INTEGER,origin_name TEXT)')
            db.execute('INSERT INTO images VALUES (?,?,?,?)',('OLD',1,7,'mzt-12325.jpg'))
        with sqlite3.connect(state/'imports.sqlite') as db:
            db.execute('CREATE TABLE imports(scope TEXT,channel INTEGER,message INTEGER,phase TEXT,result TEXT)')
            db.execute('CREATE TABLE history_jobs(scope TEXT,channel INTEGER,data TEXT)')
            db.execute('CREATE TABLE source_messages(scope TEXT,channel INTEGER,snapshot INTEGER,message INTEGER,post TEXT)')
            for n in m.MESSAGES:
                db.execute('INSERT INTO imports VALUES (?,?,?,?,?)',('scope',m.CHANNEL,n,'uploading',None))
                db.execute('INSERT INTO source_messages VALUES (?,?,?,?,?)',('scope',m.CHANNEL,22569,n,m.GROUP.split(':')[1]))
            db.execute('INSERT INTO imports VALUES (?,?,?,?,?)',('scope',m.CHANNEL,12325,'done',json.dumps({'key':'OLD'})))
            db.execute('INSERT INTO history_jobs VALUES (?,?,?)',('scope',m.CHANNEL,json.dumps({'offset':12325,'order':'oldest','completed':False,'snapshot':22569,'group_cursor':'complete-v2','stats':{'imported':11468}})))
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
                self.assertEqual(db.execute('SELECT message,phase FROM imports').fetchall(),[(12325,'done')])
                self.assertEqual(db.execute('SELECT COUNT(*) FROM source_messages').fetchone()[0],5)
            self.assertEqual(before,(lsky/'database/database.sqlite').read_bytes());self.assertEqual((importer/'state/user.session').read_bytes(),b'SESSION')
            self.assertEqual(len(list(importer.glob('recovery-unsent-*'))),1);self.assertEqual(len(list(root.glob('*.json'))),0)

    def test_refuses_new_result_or_wrong_group_members(self):
        for kind in ('sent','index','record'):
            with self.subTest(kind=kind),tempfile.TemporaryDirectory() as tmp:
                lsky,importer,root=self.fixture(Path(tmp))
                if kind=='sent':
                    p=next(root.glob('*.json'));data=json.loads(p.read_text());data['message_ids']=[999];p.write_text(json.dumps(data))
                elif kind=='index':
                    with sqlite3.connect(importer/'state/imports.sqlite') as db:db.execute('UPDATE source_messages SET post=? WHERE message=12330',('g999',))
                else:
                    with sqlite3.connect(lsky/'database/database.sqlite') as db:db.execute('INSERT INTO images VALUES (?,?,?,?)',('K12330',1,7,'mzt-12330.jpg'))
                with self.assertRaises(RuntimeError):m.recover(lsky,importer,True)
                self.assertEqual(len(list(root.glob('*.json'))),1);self.assertFalse(list(importer.glob('recovery-unsent-*')))


if __name__=='__main__':unittest.main()
