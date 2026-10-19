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
    async def iter_messages(self, entity, limit=None, max_id=0, reverse=False, **kw):
        ids=[n for n in range(4,0,-1) if not max_id or n<max_id]
        if reverse:ids.reverse()
        for n in (ids[:limit] if limit else ids):
            yield NS(id=n, photo=True, file=NS(size=3))
    async def get_messages(self,entity,ids):
        return [NS(id=n,photo=True,file=NS(size=3)) for n in ids]
    async def download_media(self, msg, file):
        self.downloads += 1
        Path(file).write_bytes(b'abc')


class FakeApi:
    def __init__(self, fail=False): self.uploads = 0; self.fail = fail; self.filenames = []
    def check(self): return 'scope'
    def upload_group(self, items, source_group):
        self.uploads += 1
        self.filenames.extend(item['filename'] for item in items)
        if self.fail: raise m.ImportFailure('response lost')
        return [{'key': 'ABC'+str(i), 'url': 'https://example.test/a.jpg'} for i in range(len(items))]


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

    def test_only_photos_are_selected(self):
        for ext in ['.svg','.mp4','.zip','.png']:
            msg=NS(id=1,photo=None,document=True,file=NS(ext=ext))
            self.assertEqual(m.select_posts([msg],20,200),[])

    def test_preview_no_download_or_database(self):
        with tempfile.TemporaryDirectory() as tmp:
            client = FakeTelegram()
            asyncio.run(m.collect(client, NS(id=1), NS(command='preview', limit=2, scan=20), Path(tmp), {}))
            self.assertEqual(client.downloads, 0)
            self.assertFalse(list(Path(tmp).iterdir()))

    def test_preserve_distinct_posts_and_resume(self):
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
            self.assertEqual(api.uploads, 2)
            self.assertEqual(api.filenames, ['mzt-4.jpg', 'mzt-3.jpg'])
            self.assertEqual(client.downloads, 2)
            self.assertFalse(list(Path(tmp).glob('download-*')))
            self.assertEqual(m.State(Path(tmp)/'imports.sqlite').get('scope', 1, 3)[0], 'done')

    def test_uncertain_upload_blocks_automatic_resubmit(self):
        with tempfile.TemporaryDirectory() as tmp:
            client = FakeTelegram(); api = FakeApi(fail=True)
            args = NS(command='import', limit=1, scan=20, max_bytes=25*1024**2)
            for n in range(2):
                with self.assertRaises(m.ImportFailure):
                    asyncio.run(m.collect(client, NS(id=1), args, Path(tmp), {}, api))
            self.assertEqual(api.uploads, 1)
            self.assertFalse(list(Path(tmp).glob('download-*')))
            self.assertEqual(m.State(Path(tmp)/'imports.sqlite').get('scope', 1, 4)[0], 'uploading')

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

    def test_source_groups_keep_order_and_never_split_at_limit(self):
        def photo(n, group=None):return NS(id=n,grouped_id=group,photo=True)
        posts=m.select_posts([photo(9,1),photo(8,1),photo(7,2),photo(6,2),photo(5,2)],4,200)
        self.assertEqual([[x.id for x in members] for _,members in posts],[[8,9]])
        self.assertEqual(m.select_posts([photo(9,1),photo(8,1)],20,2),[])
        posts=m.select_posts([photo(9,1),photo(8,1),NS(id=7,grouped_id=2,photo=False)],20,200)
        self.assertEqual(len(posts),1)

    def test_group_reply_requires_source_hashes_and_album(self):
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp)/'mzt-1.jpg';path.write_bytes(b'abc');hashes=m.digest(path)
            api=m.Lsky.__new__(m.Lsky);api.origin='https://example.test';api.strategy=2;api.album_id=7
            class Client:
                wrong=False
                def post(self,*a,**kw):
                    assert kw['data']['source_group']=='1:g123'
                    assert kw['files'][0][0]=='files[]'
                    return Response({'status':True,'data':{'images':[{'key':'K','album_id':8 if self.wrong else 7,'links':{'url':'https://example.test/K'},'source':hashes,'md5':'TG-processed'}]}})
            api.client=Client();items=[{'path':path,'filename':path.name,'hashes':hashes}]
            self.assertEqual(api.upload_group(items,'1:g123')[0]['key'],'K')
            api.client.wrong=True
            with self.assertRaises(m.ImportFailure):api.upload_group(items,'1:g123')

    def test_old_server_rejected_before_any_upload(self):
        api=m.Lsky.__new__(m.Lsky);api.origin='https://example.test'
        class Client:
            def get(self,*a,**kw):return Response({'status':True,'data':{'email':'a@example.test'}})
        api.client=Client()
        with self.assertRaises(m.ImportFailure):api.check()



if __name__ == '__main__': unittest.main()
