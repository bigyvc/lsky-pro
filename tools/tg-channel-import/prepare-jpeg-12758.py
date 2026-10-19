"""Re-encode only nine downloaded diagnostic JPEGs; no Telegram requests."""
from pathlib import Path
import hashlib,json,os,subprocess,time

PHP=r'''
$root='/tmp/diagnostic-12758';$out='/tmp/lsky-normalized-12758';
if (file_exists($out)) {fwrite(STDERR,"输出目录已存在，停止\n");exit(1);}
mkdir($out,0700);
foreach(range(12758,12766) as $id){
 $name='mzt-'.$id.'.jpg';$src=$root.'/'.$name;
 $im=new Imagick($src);
 if($im->getNumberImages()!==1 || $im->getImageWidth()!==960 || $im->getImageHeight()!==1280)throw new RuntimeException('源图尺寸或帧数变化');
 $im->transformImageColorspace(Imagick::COLORSPACE_SRGB);
 $im->stripImage();$im->setImageFormat('jpeg');$im->setImageCompression(Imagick::COMPRESSION_JPEG);
 $im->setImageCompressionQuality(95);$im->setInterlaceScheme(Imagick::INTERLACE_NO);
 $im->writeImage($out.'/'.$name);$im->clear();
 $check=new Imagick($out.'/'.$name);$check->getImageSignature();$check->clear();
 echo $name.' 已重新编码'.PHP_EOL;
}
'''

def main():
    if subprocess.check_output(['docker','inspect','--format','{{.State.Running}}','tg-import-oldest-fast'],text=True).strip()!='false':raise RuntimeError('先停止采集器')
    state=Path('/opt/tg-import/state');source=state/'diagnostic-12758';dest=state/'normalized-12758'
    if dest.exists():raise RuntimeError('输出目录已存在，未覆盖')
    for mid in range(12758,12767):
        p=source/f'mzt-{mid}.jpg'
        if not p.is_file() or not 0<p.stat().st_size<8*1024**2:raise RuntimeError('原图不完整')
    subprocess.run(['docker','exec','lsky-pro','php','-r',PHP],check=True)
    subprocess.run(['docker','cp','lsky-pro:/tmp/lsky-normalized-12758',str(dest)],check=True)
    manifest={}
    for mid in range(12758,12767):
        name=f'mzt-{mid}.jpg';p=dest/name
        if not 0<p.stat().st_size<8*1024**2:raise RuntimeError('重编码文件大小异常')
        manifest[str(mid)]={'original_sha256':hashlib.sha256((source/name).read_bytes()).hexdigest(),'sha256':hashlib.sha256(p.read_bytes()).hexdigest()}
        os.chown(p,10001,10001);os.chmod(p,0o600)
    m=dest/'manifest.json';m.write_text(json.dumps(manifest));os.chown(m,10001,10001);os.chmod(m,0o600)
    os.chown(dest,10001,10001);os.chmod(dest,0o700)
    print('九张标准JPEG已准备；原图保留，未发送TG。')

if __name__=='__main__':main()
