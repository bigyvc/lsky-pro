"""Offline history, checkpoint and rate-control regressions. No Telegram credentials."""
import asyncio
import importlib.util
import io
import json
from pathlib import Path
import tempfile
from contextlib import redirect_stdout
from types import SimpleNamespace as NS
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('collector', Path(__file__).resolve().parents[1]/'tools/tg-channel-import/importer.py')
m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)


def photo(n, group=None, size=3, **kw):
    return NS(id=n, grouped_id=group, photo=True, file=NS(size=size), noforwards=False, **kw)


class Telegram:
    def __init__(self, messages, fail_at=None):
        self.messages = messages; self.downloads = []; self.fail_at = fail_at; self.requests = []
    async def iter_messages(self, entity, limit=None, offset_id=0, max_id=0, wait_time=None, reverse=False):
        self.requests.append(dict(limit=limit, offset_id=offset_id, max_id=max_id))
        rows = [msg for msg in self.messages if (not offset_id or (msg.id > offset_id if reverse else msg.id < offset_id)) and (not max_id or msg.id < max_id)]
        if reverse: rows = list(reversed(rows))
        for n, msg in enumerate(rows[:limit] if limit else rows):
            if msg.id == self.fail_at:
                raise OSError('metadata interrupted')
            # Force async/page scheduling boundaries between members of the same group.
            await asyncio.sleep(0)
            yield msg
    async def get_messages(self, entity, ids):
        found={msg.id:msg for msg in self.messages}
        return [found.get(n) for n in ids]
    async def download_media(self, msg, file, progress_callback=None):
        self.downloads.append(msg.id)
        Path(file).write_bytes(b'abc')
        if progress_callback:
            await progress_callback(3, 3)


class API:
    def __init__(self, fail=False): self.groups = []; self.fail = fail
    def check(self): return 'scope'
    def upload_group(self, items, source_group):
        self.groups.append((source_group, [item['filename'] for item in items]))
        if self.fail: raise m.ImportFailure('uncertain POST')
        return [{'key':'K'+item['filename'], 'url':'https://example.test/file'} for item in items]


def args(command='import-history', max_posts=0):
    return NS(command=command, max_bytes=8*1024**2, max_posts=max_posts, interval=0, download_kib=0)


def run(coro):
    with redirect_stdout(io.StringIO()) as output:
        asyncio.run(coro)
    return [json.loads(line) for line in output.getvalue().splitlines()]


class HistoryTests(unittest.TestCase):
    def test_plan_scans_all_groups_without_download_api_or_state(self):
        with tempfile.TemporaryDirectory() as tmp:
            tg=Telegram([photo(8,20),photo(7,20),photo(6),NS(id=5, grouped_id=None, photo=False)])
            logs=run(m.history(tg,NS(id=1),args('plan'),Path(tmp)))
            result=logs[-1]
            self.assertEqual(result['mode'],'completed')
            self.assertEqual(result['stats']['eligible_photos'],3)
            self.assertEqual(result['stats']['source_bytes'],9)
            self.assertEqual(result['estimated_network_bytes']['public_api_roundtrip'],45)
            self.assertEqual(tg.downloads,[])
            self.assertEqual(list(Path(tmp).iterdir()),[])

    def test_pause_resume_keeps_groups_and_freezes_snapshot(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp); tg=Telegram([photo(8,20),photo(7,20),photo(6,19),photo(5,19),photo(4)])
            api=API()
            run(m.history(tg,NS(id=1),args(max_posts=1),root,api))
            job=m.State(root/'imports.sqlite').job('scope',1)
            self.assertEqual(job['offset'],8);self.assertFalse(job['completed'])
            tg.messages.insert(0,photo(9))  # Newer posts must not move a resumed snapshot.
            run(m.history(tg,NS(id=1),args(),root,api))
            self.assertEqual(api.groups,[('1:g20',['mzt-7.jpg','mzt-8.jpg']),('1:g19',['mzt-5.jpg','mzt-6.jpg']),('1:s4',['mzt-4.jpg'])])
            self.assertEqual(tg.downloads,[7,8,5,6,4])
            job=m.State(root/'imports.sqlite').job('scope',1)
            self.assertTrue(job['completed']);self.assertEqual(job['stats']['imported'],5)
            before=len(api.groups);run(m.history(tg,NS(id=1),args(),root,api))
            self.assertEqual(before,len(api.groups))
            self.assertFalse(list(root.glob('download-*')))

    def test_recent_imports_are_reused_without_downloading(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp); state=m.State(root/'imports.sqlite')
            for n in (8,7):state.save('scope',1,n,'done','sha',{'key':'K'})
            api=API(); tg=Telegram([photo(8,20),photo(7,20),photo(6)])
            run(m.history(tg,NS(id=1),args(),root,api))
            job=state.job('scope',1)
            self.assertEqual(job['stats']['reused'],2)
            self.assertEqual(tg.downloads,[6]);self.assertEqual(len(api.groups),1)

    def test_lost_post_response_blocks_resend_and_cursor(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp); api=API(True); tg=Telegram([photo(8,20),photo(7,20),photo(6)])
            for _ in range(2):
                with self.assertRaises(m.ImportFailure),redirect_stdout(io.StringIO()):
                    asyncio.run(m.history(tg,NS(id=1),args(),root,api))
            state=m.State(root/'imports.sqlite')
            self.assertEqual(len(api.groups),1);self.assertEqual(state.job('scope',1)['offset'],0)
            self.assertEqual([state.get('scope',1,n)[0] for n in (8,7)],['uploading','uploading'])

    def test_metadata_failure_does_not_flush_incomplete_album(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);api=API();tg=Telegram([photo(9),photo(8,20),photo(7,20),photo(6)],fail_at=7)
            with self.assertRaises(OSError),redirect_stdout(io.StringIO()):
                asyncio.run(m.history(tg,NS(id=1),args(),root,api))
            self.assertEqual(len(api.groups),0)
            self.assertEqual(m.State(root/'imports.sqlite').job('scope',1)['offset'],0)
            tg.fail_at=None
            run(m.history(tg,NS(id=1),args(),root,api))
            self.assertEqual([row[0] for row in api.groups],['1:s9','1:g20','1:s6'])

    def test_crash_after_recorded_result_before_checkpoint_reuses_post(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);api=API();tg=Telegram([photo(8,20),photo(7,20),photo(6)])
            original=m.State.checkpoint; calls=0
            def crash(self,scope,channel,job,skipped=None):
                nonlocal calls
                calls+=1
                if calls==2:raise OSError('disk write interrupted')
                return original(self,scope,channel,job,skipped)
            with patch.object(m.State,'checkpoint',crash),self.assertRaises(OSError),redirect_stdout(io.StringIO()):
                asyncio.run(m.history(tg,NS(id=1),args(),root,api))
            run(m.history(tg,NS(id=1),args(),root,api))
            self.assertEqual(len(api.groups),2)
            self.assertEqual(m.State(root/'imports.sqlite').job('scope',1)['stats']['reused'],2)

    def test_skip_whole_mixed_protected_and_oversize_groups_with_audit(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);api=API(); protected=photo(6);protected.noforwards=True
            tg=Telegram([photo(9,20),NS(id=8,grouped_id=20,photo=False),photo(7,size=9*1024**2),protected,photo(5)])
            run(m.history(tg,NS(id=1),args(),root,api))
            state=m.State(root/'imports.sqlite');job=state.job('scope',1)
            self.assertEqual(job['stats']['skipped_photos'],3)
            self.assertEqual(tg.downloads,[5]);self.assertEqual(len(api.groups),1)
            self.assertEqual(state.db.execute('SELECT COUNT(*) FROM history_skips').fetchone()[0],4)

    def test_partial_legacy_post_stops_and_keeps_checkpoint(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);m.State(root/'imports.sqlite').save('scope',1,8,'done','sha')
            api=API()
            with self.assertRaises(m.ImportFailure),redirect_stdout(io.StringIO()):
                asyncio.run(m.history(Telegram([photo(8,20),photo(7,20)]),NS(id=1),args(),root,api))
            self.assertEqual(api.groups,[])
            self.assertEqual(m.State(root/'imports.sqlite').job('scope',1)['offset'],0)

    def test_oldest_first_complete_groups_and_resume_at_max_member(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);api=API();tg=Telegram([photo(7),photo(6,20),photo(5,20),photo(3,10),photo(2,10),photo(1)])
            options=args(max_posts=2);options.order='oldest';options.until=6
            run(m.history(tg,NS(id=1),options,root,api))
            state=m.State(root/'imports.sqlite');job=state.job('scope',1)
            self.assertEqual(job['offset'],2);self.assertEqual(job['snapshot'],6)
            options.max_posts=0
            run(m.history(tg,NS(id=1),options,root,api))
            self.assertEqual(api.groups,[('1:s1',['mzt-1.jpg']),('1:g10',['mzt-2.jpg','mzt-3.jpg']),('1:g20',['mzt-5.jpg','mzt-6.jpg'])])
            self.assertEqual(tg.downloads,[1,2,3,5,6]);self.assertTrue(state.job('scope',1)['completed'])
            options.order='newest'
            with self.assertRaises(m.ImportFailure),redirect_stdout(io.StringIO()):
                asyncio.run(m.history(tg,NS(id=1),options,root,api))

    def test_inclusive_upper_bound_cannot_split_a_source_album(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);api=API();tg=Telegram([photo(7,20),photo(6,20),photo(1)])
            options=args();options.order='oldest';options.until=6
            with self.assertRaises(m.ImportFailure),redirect_stdout(io.StringIO()):
                asyncio.run(m.history(tg,NS(id=1),options,root,api))
            self.assertEqual(tg.downloads,[]);self.assertEqual(api.groups,[])

    def test_reset_is_scoped_backed_up_and_preserves_user_session(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);state=m.State(root/'imports.sqlite')
            state.save('scope',1,2,'done','sha');state.save('other',1,2,'done','sha')
            (root/'user.session').write_bytes(b'private session')
            with redirect_stdout(io.StringIO()):m.reset_import_state(root,'scope',1)
            self.assertIsNotNone(state.get('scope',1,2))
            with redirect_stdout(io.StringIO()):m.reset_import_state(root,'scope',1,True)
            self.assertIsNone(state.get('scope',1,2));self.assertIsNotNone(state.get('other',1,2))
            self.assertEqual((root/'user.session').read_bytes(),b'private session')
            backups=list(root.glob('imports-before-reset-*.sqlite'));self.assertEqual(len(backups),1)
            self.assertIsNotNone(m.State(backups[0]).get('scope',1,2))
            state.save('scope',1,3,'uploading','sha')
            with self.assertRaises(m.ImportFailure),redirect_stdout(io.StringIO()):m.reset_import_state(root,'scope',1,True)
            self.assertIsNotNone(state.get('scope',1,3))

    def test_large_history_has_no_recent_window_limit(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            logs=run(m.history(Telegram([photo(n,n//2) for n in range(5001,0,-1)]),NS(id=1),args('plan'),root))
            self.assertEqual(logs[-1]['stats']['eligible_photos'],5001)
            self.assertTrue(logs[-1]['completed'])
            self.assertTrue(any(row['mode']=='plan' for row in logs))

    def test_download_failure_is_safe_to_resume_before_any_post(self):
        class Broken(Telegram):
            async def download_media(self, msg, file, **kw):
                Path(file).write_bytes(b'ab')
                raise OSError('source connection lost')
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);api=API()
            with self.assertRaises(m.ImportFailure),redirect_stdout(io.StringIO()):
                asyncio.run(m.history(Broken([photo(2),photo(1)]),NS(id=1),args(),root,api))
            self.assertEqual(api.groups,[])
            self.assertIsNone(m.State(root/'imports.sqlite').get('scope',1,2))
            self.assertFalse(list(root.glob('download-*')))
            run(m.history(Telegram([photo(2),photo(1)]),NS(id=1),args(),root,api))
            self.assertEqual(len(api.groups),2)

    def test_pacing_and_live_status_are_non_destructive(self):
        elapsed=[0.0];waited=[]
        async def sleep(delay):waited.append(delay);elapsed[0]+=delay
        with patch.object(m.time,'monotonic',lambda:elapsed[0]),patch.object(m.asyncio,'sleep',sleep):
            callback=m.DownloadPacer(1024).callback()
            asyncio.run(callback(1024*1024,1024*1024))
            self.assertEqual(waited,[1.0])
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);api=API();tg=Telegram([photo(1)])
            run(m.history(tg,NS(id=1),args(),root,api))
            stale=root/'download-live';stale.mkdir()
            with patch.dict(m.os.environ,{'STATE_DIR':str(root)}),patch.object(m.sys,'argv',['importer.py','status']),redirect_stdout(io.StringIO()) as out:
                m.main()
            self.assertTrue(stale.exists())
            self.assertIn('"mode": "status"',out.getvalue())


    def test_interleaved_groups_reunite_before_any_upload(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);api=API();tg=Telegram([photo(5774,2),photo(5773,1),photo(5772,2),photo(5771,1),photo(5770)])
            options=args();options.order='oldest';options.until=5774
            run(m.history(tg,NS(id=1),options,root,api))
            self.assertEqual(api.groups,[('1:s5770',['mzt-5770.jpg']),('1:g1',['mzt-5771.jpg','mzt-5773.jpg']),('1:g2',['mzt-5772.jpg','mzt-5774.jpg'])])
            self.assertEqual(m.State(root/'imports.sqlite').job('scope',1)['stats']['imported'],5)

    def test_interleaved_restart_cannot_skip_nested_group(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);api=API();tg=Telegram([photo(10,1),photo(4,2),photo(3,2),photo(1,1)])
            options=args(max_posts=1);options.order='oldest'
            run(m.history(tg,NS(id=1),options,root,api))
            self.assertEqual(m.State(root/'imports.sqlite').job('scope',1)['offset'],1)
            options.max_posts=0
            run(m.history(tg,NS(id=1),options,root,api))
            self.assertEqual(api.groups,[('1:g1',['mzt-1.jpg','mzt-10.jpg']),('1:g2',['mzt-3.jpg','mzt-4.jpg'])])
            self.assertEqual(m.State(root/'imports.sqlite').job('scope',1)['stats']['imported'],4)

    def test_legacy_cursor_crossing_stops_before_download_or_post(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);state=m.State(root/'imports.sqlite');api=API()
            state.checkpoint('scope',1,{'order':'oldest','requested_until':4,'snapshot':4,'offset':2,'completed':False,'ceiling':8*1024**2,'stats':m.history_stats()})
            state.save('scope',1,1,'done','sha',{'key':'old1'});state.save('scope',1,2,'done','sha',{'key':'old2'})
            tg=Telegram([photo(4,2),photo(3,1),photo(2,2),photo(1,1)])
            options=args();options.order='oldest';options.until=4
            with self.assertRaisesRegex(m.ImportFailure,'旧游标'),redirect_stdout(io.StringIO()):
                asyncio.run(m.history(tg,NS(id=1),options,root,api))
            self.assertEqual(api.groups,[]);self.assertEqual(tg.downloads,[]);self.assertEqual(state.job('scope',1)['offset'],2)

    def test_index_failure_never_uploads_and_rebuilds_on_resume(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);api=API();tg=Telegram([photo(4,1),photo(3,2),photo(2,2),photo(1,1)],fail_at=3)
            options=args();options.order='oldest'
            with self.assertRaises(OSError),redirect_stdout(io.StringIO()):
                asyncio.run(m.history(tg,NS(id=1),options,root,api))
            self.assertEqual(api.groups,[])
            tg.fail_at=None;run(m.history(tg,NS(id=1),options,root,api))
            self.assertEqual(len(api.groups),2)

    def test_source_download_timeout_retries_before_upload_intents(self):
        class Hanging(Telegram):
            async def download_media(self,msg,file,**kwargs):
                self.downloads.append(msg.id);Path(file).write_bytes(b'partial')
                await asyncio.Event().wait()
        async def attempt():
            with tempfile.TemporaryDirectory() as tmp:
                root=Path(tmp);api=API();tg=Hanging([photo(1)])
                options=args();options.download_timeout=.01;options.download_attempts=2
                with self.assertRaises(m.ImportFailure),redirect_stdout(io.StringIO()):
                    with patch.object(m.asyncio,'sleep',new=no_sleep):
                        await m.import_post(tg,NS(id=1),[photo(1)],root,m.State(root/'imports.sqlite'),'scope',api,options)
                self.assertEqual(tg.downloads,[1,1]);self.assertEqual(api.groups,[])
                self.assertIsNone(m.State(root/'imports.sqlite').get('scope',1,1));self.assertFalse(list(root.glob('download-*')))
        async def no_sleep(_):pass
        asyncio.run(attempt())

    def test_reject_bad_pagination_and_changed_filter(self):
        async def gather():return [p async for p in m.history_posts(Telegram([photo(2),photo(3)]),NS(id=1),3,0)]
        with self.assertRaises(m.ImportFailure):asyncio.run(gather())
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);api=API();tg=Telegram([photo(2),photo(1)])
            run(m.history(tg,NS(id=1),args(max_posts=1),root,api))
            changed=args();changed.max_bytes=1024
            with self.assertRaises(m.ImportFailure),redirect_stdout(io.StringIO()):
                asyncio.run(m.history(tg,NS(id=1),changed,root,api))


if __name__=='__main__':unittest.main()
