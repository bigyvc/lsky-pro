import fcntl, importlib.util, json, os, tempfile, time, unittest
from pathlib import Path
spec=importlib.util.spec_from_file_location('cleaner',Path(__file__).parents[1]/'tools/telegram-cache-cleaner.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class CleanerTest(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name);self.data=self.root/'data';self.reg=self.root/'reg';self.data.mkdir();self.reg.mkdir()
 def tearDown(self): self.temp.cleanup()
 def item(self,name,age=4000,parent='123/documents'):
  p=self.data/parent/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b'x'*100);s=p.stat()
  r=self.reg/(name+'.json');r.write_text(json.dumps(dict(root='/var/lib/telegram-bot-api',relative=str(p.relative_to(self.data)),device=s.st_dev,inode=s.st_ino,size=s.st_size,mtime=int(s.st_mtime),accessed=int(time.time())-age)))
  return p,r
 def run_clean(self,**kw): return m.clean(self.reg,self.data,min_free=0,**kw)
 def test_age_and_unregistered_state(self):
  old,_=self.item('old');fresh,_=self.item('fresh',10);state,_=self.item('db.sqlite',parent='123');untracked=self.data/'123/documents/unknown';untracked.write_bytes(b'x')
  r=self.run_clean();self.assertEqual(r['removed'],1);self.assertFalse(old.exists());self.assertTrue(fresh.exists() and state.exists() and untracked.exists())
 def test_dry_run(self):
  p,r=self.item('old');out=self.run_clean(dry_run=True);self.assertEqual(out['removed'],1);self.assertTrue(p.exists() and r.exists())
 def test_active_read_and_global_lock(self):
  p,_=self.item('read')
  with p.open('rb') as f:
   fcntl.flock(f,fcntl.LOCK_SH);self.assertEqual(self.run_clean()['removed'],0)
  with (self.reg/'.lock').open('a') as f:
   fcntl.flock(f,fcntl.LOCK_SH);self.assertTrue(self.run_clean()['busy'])
  self.assertEqual(self.run_clean()['removed'],1)
 def test_pressure_respects_recent_access(self):
  old,_=self.item('old',600);fresh,_=self.item('fresh',100)
  self.assertEqual(self.run_clean(max_bytes=0)['removed'],1);self.assertTrue(fresh.exists());self.assertFalse(old.exists())
 def test_capacity_only_preserves_old_files_below_limit(self):
  old,_=self.item('old',1000000)
  self.assertEqual(self.run_clean(ttl=0,max_bytes=15*1024**3)['removed'],0);self.assertTrue(old.exists())
 def test_capacity_only_evicts_oldest_until_below_limit(self):
  old,_=self.item('old',10000);newer,_=self.item('newer',1000)
  self.assertEqual(self.run_clean(ttl=0,max_bytes=150)['removed'],1);self.assertFalse(old.exists());self.assertTrue(newer.exists())
 def test_capacity_only_still_respects_active_reads(self):
  old,_=self.item('old',10000)
  with old.open('rb') as f:
   fcntl.flock(f,fcntl.LOCK_SH);self.assertEqual(self.run_clean(ttl=0,max_bytes=0)['removed'],0)
  self.assertEqual(self.run_clean(ttl=0,max_bytes=0)['removed'],1)
 def test_tampering_and_replaced_file(self):
  p,r=self.item('replacement');p.write_bytes(b'changed')
  outside=self.root/'outside';outside.write_bytes(b'secret');link=self.data/'123/documents/link';link.symlink_to(outside)
  self.item('evil');j=json.loads((self.reg/'evil.json').read_text());j['relative']='../outside';(self.reg/'evil.json').write_text(json.dumps(j))
  j['relative']='123/documents/link';(self.reg/'link.json').write_text(json.dumps(j))
  self.assertEqual(self.run_clean()['removed'],0);self.assertTrue(p.exists() and outside.exists())
if __name__=='__main__':unittest.main()
