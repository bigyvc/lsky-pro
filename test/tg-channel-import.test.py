import asyncio
import importlib.util
import json
from pathlib import Path
import tempfile
from types import SimpleNamespace as NS
import unittest

MODULE = Path(__file__).resolve().parents[1] / 'tools/tg-channel-import/importer.py'
spec = importlib.util.spec_from_file_location('collector', MODULE)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class Response:
    status_code = 200
    def __init__(self, data): self.data = data
    def json(self): return self.data


class FakeTelegram:
    def __init__(self): self.downloads = 0
    async def iter_messages(self, entity, limit):
        for n in range(1, 5):
            yield NS(id=n, photo=True, file=NS(size=3))
    async def download_media(self, msg, file):
        self.downloads += 1
        Path(file).write_bytes(b'abc')


class FakeApi:
    def __init__(self, fail=False): self.uploads = 0; self.fail = fail
    def check(self): return 'scope'
    def upload(self, path, filename, hashes):
        self.uploads += 1
        if self.fail: raise m.ImportFailure('response lost')
        return {'key': 'ABC123', 'url': 'https://example.test/ABC123/a.jpg'}


class Tests(unittest.TestCase):
    def test_channel_and_https_validation(self):
        self.assertEqual(m.channel_name('https://t.me/botmzt'), '@botmzt')
        for bad in ['https://evil.test/x', 'https://t.me/botmzt/42', '../a', '']:
            with self.assertRaises(m.ImportFailure): m.channel_name(bad)
        for bad in ['http://example.test', 'https://u:p@example.test', 'https://example.test/path', 'https://example.test?token=secret']:
            with self.assertRaises(m.ImportFailure): m.api_origin(bad)

    def test_http200_business_failure_not_success(self):
        for body in [{'status': False}, {'status': 1}, [], None]:
            with self.assertRaises(m.ImportFailure): m.api_data(Response(body))
        self.assertEqual(m.api_data(Response({'status': True, 'data': {'key': 'x'}})), {'key': 'x'})

    def test_no_video_svg_or_arbitrary_document(self):
        for ext in ['.svg', '.mp4', '.zip']:
            self.assertIsNone(m.extension(NS(photo=None, document=True, file=NS(ext=ext))))
        self.assertEqual(m.extension(NS(photo=None, document=True, file=NS(ext='.PNG'))), '.png')

    def test_preview_no_download_or_database(self):
        with tempfile.TemporaryDirectory() as tmp:
            client = FakeTelegram()
            asyncio.run(m.collect(client, NS(id=1), NS(command='preview', limit=2, scan=20), Path(tmp), {}))
            self.assertEqual(client.downloads, 0)
            self.assertFalse(list(Path(tmp).iterdir()))

    def test_hash_dedupe_and_resume(self):
        with tempfile.TemporaryDirectory() as tmp:
            client = FakeTelegram(); api = FakeApi()
            args = NS(command='import', limit=2, scan=20, max_bytes=25*1024**2)
            old_sleep = m.asyncio.sleep
            async def no_wait(n): pass
            m.asyncio.sleep = no_wait
            try:
                asyncio.run(m.collect(client, NS(id=1), args, Path(tmp), {}, api))
                asyncio.run(m.collect(client, NS(id=1), args, Path(tmp), {}, api))
            finally: m.asyncio.sleep = old_sleep
            self.assertEqual(api.uploads, 1)
            self.assertEqual(client.downloads, 2)
            self.assertFalse(list(Path(tmp).glob('download-*')))
            self.assertEqual(m.State(Path(tmp)/'imports.sqlite').get('scope', 1, 2)[0], 'done')

    def test_uncertain_upload_blocks_automatic_resubmit(self):
        with tempfile.TemporaryDirectory() as tmp:
            client = FakeTelegram(); api = FakeApi(fail=True)
            args = NS(command='import', limit=1, scan=20, max_bytes=25*1024**2)
            for n in range(2):
                with self.assertRaises(m.ImportFailure):
                    asyncio.run(m.collect(client, NS(id=1), args, Path(tmp), {}, api))
            self.assertEqual(api.uploads, 1)
            self.assertFalse(list(Path(tmp).glob('download-*')))
            self.assertEqual(m.State(Path(tmp)/'imports.sqlite').get('scope', 1, 1)[0], 'uploading')

    def test_album_resolution_paginates_and_requires_unique_name(self):
        api=m.Lsky.__new__(m.Lsky);api.origin='https://example.test';api.album_name='频道图片';api.album_id=0
        class Client:
            def get(self,url,params):
                page=params['page']
                albums=[{'id':1,'name':'Other'}] if page==1 else [{'id':7,'name':'频道图片'}]
                return Response({'status':True,'data':{'data':albums,'next_page_url':'next' if page==1 else None}})
        api.client=Client();self.assertEqual(api.resolve_album(),7)
        class Duplicates:
            def get(self,*a,**kw):
                return Response({'status':True,'data':{'data':[{'id':7,'name':'频道图片'},{'id':8,'name':'频道图片'}],'next_page_url':None}})
        api.client=Duplicates()
        with self.assertRaises(m.ImportFailure):api.resolve_album()
        api.album_id=8;self.assertEqual(api.resolve_album(),8)
        api.album_id=9
        with self.assertRaises(m.ImportFailure):api.resolve_album()

    def test_old_server_rejected_before_any_upload(self):
        api=m.Lsky.__new__(m.Lsky);api.origin='https://example.test'
        class Client:
            def get(self,*a,**kw):return Response({'status':True,'data':{'email':'a@example.test'}})
        api.client=Client()
        with self.assertRaises(m.ImportFailure):api.check()

    def test_upload_checks_actual_hashes(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp)/'a.jpg';path.write_bytes(b'abc')
            api = m.Lsky.__new__(m.Lsky);api.origin = 'https://example.test';api.strategy = 2;api.album_id = 7
            class Client:
                def post(self, *a, **kw):
                    return Response({'status': True, 'data': {'key':'x','album_id':7,'links':{'url':'https://example.test/x'},'md5':'wrong','sha1':'wrong'}})
            api.client = Client()
            with self.assertRaises(m.ImportFailure): api.upload(path, 'a.jpg', m.digest(path))


if __name__ == '__main__': unittest.main()
