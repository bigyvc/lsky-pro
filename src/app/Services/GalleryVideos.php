<?php
namespace App\Services;

use App\Models\Image;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class GalleryVideos
{
    public function query(): \Illuminate\Database\Eloquent\Builder
    {
        $s=app(GallerySettings::class)->read();
        return Image::where('user_id',$s['user_id'])->where('album_id',(int)($s['video_album_id']??0))
            ->where('is_unhealthy',false)->whereIn('extension',array_diff(VideoMetadata::VIDEO_EXTENSIONS,['m3u8']))
            ->where('mimetype','like','video/%')->whereNotNull('strategy_id');
    }

    public function find(string $key): Image { return $this->query()->where('key',$key)->firstOrFail(); }

    public function fingerprint(Image $image): string
    {
        return hash('sha256',$image->id.'|'.$image->sha1.'|'.$image->size.'|'.$image->extension);
    }

    public function metadata(Image $image): ?object
    {
        return DB::table('gallery_video_metadata')->where('image_id',$image->id)->where('fingerprint',$this->fingerprint($image))->first();
    }

    public function posterPath(Image $image): ?string
    {
        $name=$this->metadata($image)?->poster;
        if (!$name || !preg_match('/^[a-f0-9]{64}\.jpg$/D',$name)) return null;
        $path=storage_path('app/gallery-video-posters/'.$name);
        return is_file($path) ? $path : null;
    }

    public function data(Image $image): array
    {
        $meta=$this->metadata($image);$root='/gallery-video-media/'.$image->key;
        return ['key'=>$image->key,'title'=>$image->origin_name ?: $image->name,'href'=>'/videos/'.$image->key,
            'width'=>$image->width,'height'=>$image->height,'duration'=>$meta?->duration===null?null:(float)$meta->duration,
            'poster'=>$root.'/poster','url'=>$root.'/original','mime'=>$image->mimetype];
    }

    /** Operator command only. Never fetch or decode media during a visitor's request. */
    public function prepare(Image $image): void
    {
        $root=storage_path('app/gallery-video-posters');
        if (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) throw new \RuntimeException('无法创建封面目录');
        $lock=fopen($root.'/.prepare.lock','c');
        if (!$lock) throw new \RuntimeException('无法打开封面锁');
        if (!flock($lock,LOCK_EX|LOCK_NB)) {fclose($lock);throw new \RuntimeException('已有封面生成任务运行');}
        $input=null;$output=null;$stream=null;
        try {
            // Only this command's abandoned private temporary files, under its exclusive lock.
            foreach (['.input-*','.poster-*'] as $pattern) foreach (glob($root.'/'.$pattern) as $stale) {
                if (is_file($stale) && !is_link($stale)) unlink($stale);
            }
            $size=(int)round($image->size*1024);
            if ($image->width>8192 || $image->height>8192) throw new \RuntimeException('视频尺寸超出封面处理上限');
            if ($size<1 || $size>512*1024*1024 || disk_free_space($root)<$size+2*1024**3) throw new \RuntimeException('文件超限或磁盘空间不足（保留2GiB）');
            $input=tempnam($root,'.input-');$output=tempnam($root,'.poster-');
            if (!$input || !$output) throw new \RuntimeException('无法创建临时文件');
            $stream=$image->filesystem()->readStream($image->pathname);
            if (!is_resource($stream)) throw new \RuntimeException('视频暂时无法读取');
            $dest=fopen($input,'wb');
            try {$bytes=stream_copy_to_stream($stream,$dest,$size+1);} finally {if(is_resource($dest))fclose($dest);}
            if ($bytes!==$size) throw new \RuntimeException('视频长度不符，未生成封面');
            // Explicit demuxers and local-file-only protocols prevent playlists/network dereferences.
            $format=match(strtolower($image->extension)) {
                'mp4','mov','m4v','3gp'=>'mov','webm','mkv'=>'matroska','avi'=>'avi','wmv'=>'asf',
                'flv'=>'flv','ts','m2ts','mts'=>'mpegts','hevc','h265'=>'hevc',default=>throw new \RuntimeException('不支持的封面格式'),
            };
            $base=['-v','error','-max_alloc','16777216','-protocol_whitelist','file','-threads','1','-probesize','1048576','-analyzeduration','1000000','-f',$format];
            if ($format==='mov') array_push($base,'-enable_drefs','0');
            $probe=new Process(['ffprobe',...$base,'-i',$input,'-show_entries','format=duration','-of','json']);
            $probe->setTimeout(25);$probe->mustRun();
            $duration=(float)(json_decode($probe->getOutput(),true)['format']['duration']??0);
            $fingerprint=$this->fingerprint($image);$poster=$fingerprint.'.jpg';
            $ffmpeg=new Process(['ffmpeg','-y',...$base,'-i',$input,'-map','0:v:0','-frames:v','1','-vf','scale=640:640:force_original_aspect_ratio=decrease','-threads','1','-q:v','4','-f','image2',$output]);
            $ffmpeg->setTimeout(30);$ffmpeg->mustRun();
            if (!is_file($output) || filesize($output)<1 || filesize($output)>2*1024*1024 || (@getimagesize($output)[2]??null)!==IMAGETYPE_JPEG) throw new \RuntimeException('封面生成失败');
            if (!rename($output,$root.'/'.$poster)) throw new \RuntimeException('无法保存封面');
            chmod($root.'/'.$poster,0600);
            DB::table('gallery_video_metadata')->updateOrInsert(['image_id'=>$image->id],[
                'fingerprint'=>$fingerprint,'duration'=>is_finite($duration)&&$duration>0?$duration:null,
                'poster'=>$poster,'created_at'=>now(),'updated_at'=>now()]);
        } finally {
            if(is_resource($stream))fclose($stream);
            foreach([$input,$output] as $path)if($path && is_file($path))unlink($path);
            flock($lock,LOCK_UN);fclose($lock);
        }
    }
}
