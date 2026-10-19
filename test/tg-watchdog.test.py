import importlib.util,json,tempfile,unittest,sqlite3,datetime
from pathlib import Path
from unittest.mock import patch
from types import SimpleNamespace
spec=importlib.util.spec_from_file_location('watchdog',Path(__file__).resolve().parents[1]/'tools/tg-channel-import/watchdog.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Tests(unittest.TestCase):
 def decide(self,**kw):
  args=dict(job={'updated_at':1000,'completed':False},pending=[],running=True,exit_code=0,downloads=[],now=3000,started=1000,history=[])
  args.update(kw);return m.decide(**args)
 def test_safe_recovery_and_backoff(self):
  self.assertEqual(self.decide(running=False,exit_code=1),'restart_exited')
  self.assertEqual(self.decide(downloads=[1100]),'restart_stalled_download')
  self.assertEqual(self.decide(downloads=[2900]),'running')
  self.assertEqual(self.decide(downloads=[]),'stalled_unknown')
  self.assertEqual(self.decide(pending=[(1,2)],job={'updated_at':2999}),'uploading')
  self.assertEqual(self.decide(downloads=[1100],history=[2800]),'backoff')
  self.assertEqual(self.decide(downloads=[1100],history=[1100,1800,2400]),'restart_limit')
 def test_never_retry_uncertain_or_intentional(self):
  for running in (True,False):
   self.assertEqual(self.decide(running=running,exit_code=1,pending=[(1,2)],downloads=[1100]),'blocked_uncertain')
  for code in (0,137,143):self.assertEqual(self.decide(running=False,exit_code=code),'stopped_intentionally')
  self.assertEqual(self.decide(paused=True,downloads=[1100]),'paused')
  self.assertEqual(self.decide(competing=True,running=False,exit_code=1),'another_collector')
  self.assertEqual(self.decide(job={'completed':True}),'completed')
 def test_diagnostics_are_fixed_fields(self):
  with tempfile.TemporaryDirectory() as tmp:
   root=Path(tmp);d=root/'storage/logs';d.mkdir(parents=True)
   (d/'laravel.log').write_text('Token=SECRET https://secret/bot/TOKEN\nTelegram Bot API请求失败 [sendMediaGroup]：HTTP=400 TG=400 curl=0 category=IMAGE_PROCESS_FAILED:item7 {"secret":"TOKEN"}\n')
   output=m.diagnostic(root)
   self.assertEqual(output['category'],'IMAGE_PROCESS_FAILED:item7')
   self.assertNotIn('TOKEN',json.dumps(output));self.assertNotIn('secret',json.dumps(output))
 def fixture(self,root):
  state=root/'state';state.mkdir();(state/'.lock').touch()
  with sqlite3.connect(state/'imports.sqlite') as db:
   db.execute('CREATE TABLE history_jobs(data TEXT)');db.execute('INSERT INTO history_jobs VALUES (?)',(json.dumps({'offset':1,'updated_at':1,'completed':False}),))
   db.execute('CREATE TABLE imports(channel INTEGER,message INTEGER,phase TEXT)')
  return SimpleNamespace(state=state,monitor=root/'monitor',lsky=root/'lsky',container='test-import',apply=True)
 def test_stop_race_rechecks_durable_intent(self):
  with tempfile.TemporaryDirectory() as tmp:
   args=self.fixture(Path(tmp));d=args.state/'download-x';d.mkdir();f=d/'x.jpg';f.touch()
   import os;os.utime(f,(1,1));calls=[]
   def run(cmd):
    calls.append(cmd)
    if '.State' in ' '.join(cmd):return json.dumps({'Running':True,'ExitCode':0,'StartedAt':'2020-01-01T00:00:00Z'})
    if '.Mounts' in ' '.join(cmd):return json.dumps([{'Destination':'/state','Type':'bind','Source':str(args.state)}])
    if cmd[1]=='stop':
     with sqlite3.connect(args.state/'imports.sqlite') as db:db.execute("INSERT INTO imports VALUES (1,2,'uploading')")
     return 'stopped'
    raise AssertionError('Must not start after new pending intent')
   with patch.object(m,'command',run):m.check(args)
   report=json.loads((args.monitor/'monitor.json').read_text())
   self.assertEqual(report['status'],'blocked_uncertain');self.assertFalse(any(c[1]=='start' for c in calls))
 def test_restart_budget_persisted(self):
  with tempfile.TemporaryDirectory() as tmp:
   args=self.fixture(Path(tmp));calls=[]
   def run(cmd):
    calls.append(cmd)
    if '.State' in ' '.join(cmd):return json.dumps({'Running':False,'ExitCode':1,'StartedAt':'2020-01-01T00:00:00Z'})
    if '.Mounts' in ' '.join(cmd):return json.dumps([{'Destination':'/state','Type':'bind','Source':str(args.state)}])
    if cmd[1]=='start':return 'started'
    raise AssertionError(cmd)
   with patch.object(m,'command',run):m.check(args);m.check(args)
   self.assertEqual(sum(c[1]=='start' for c in calls),1)
   self.assertEqual(json.loads((args.monitor/'monitor.json').read_text())['status'],'backoff')
if __name__=='__main__':unittest.main()
