"""One-post retry using verified locally re-encoded JPEGs, never network downloads."""
import hashlib,json,os,shutil,sys
from pathlib import Path

ROOT=Path(os.environ.get('STATE_DIR','/state'))
GROUP=13352363222278213

def prepared(message,root=ROOT):
    mid=message.id
    if mid not in range(12758,12767) or message.grouped_id!=GROUP or getattr(message.peer_id,'channel_id',None)!=1109579085:
        raise RuntimeError('拒绝处理目标组以外的照片')
    manifest=json.loads((root/'normalized-12758/manifest.json').read_text())
    data=manifest[str(mid)];name=f'mzt-{mid}.jpg'
    original=root/'diagnostic-12758'/name;image=root/'normalized-12758'/name
    for path,key in ((original,'original_sha256'),(image,'sha256')):
        if hashlib.sha256(path.read_bytes()).hexdigest()!=data[key]:raise RuntimeError('文件校验失败')
    return image

if __name__=='__main__':
    from telethon import TelegramClient
    async def local_download(self,message,*args,**kwargs):
        source=prepared(message);dest=Path(kwargs['file']);shutil.copyfile(source,dest)
        print(json.dumps({'mode':'normalized_jpeg','message':message.id}),flush=True)
        return str(dest)
    TelegramClient.download_media=local_download
    sys.path.insert(0,'/app')
    import importer
    sys.argv=['importer.py','import-history','--order','oldest','--until','22569','--max-posts','1','--interval','10']
    try:importer.main()
    except Exception as exc:
        print(str(exc) if isinstance(exc,importer.ImportFailure) else '定向试传停止：'+type(exc).__name__,file=sys.stderr)
        sys.exit(1)
