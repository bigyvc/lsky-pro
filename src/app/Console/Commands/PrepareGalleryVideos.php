<?php
namespace App\Console\Commands;

use App\Services\GallerySettings;
use App\Services\GalleryVideos;
use Illuminate\Console\Command;

class PrepareGalleryVideos extends Command
{
    protected $signature='gallery:videos {--limit=20 : 每次最多处理多少个视频} {--key= : 只处理指定视频} {--force : 重新生成已有封面}';
    protected $description='Prepare bounded video posters and duration for the explicitly published video album';
    public function handle(GalleryVideos $videos,GallerySettings $settings): int
    {
        $s=$settings->read();
        if (!$s['enabled'] || !($s['video_album_id']??0)) {$this->error('请先配置公开的视频相册');return 1;}
        $limit=filter_var($this->option('limit'),FILTER_VALIDATE_INT);
        if (!$limit || $limit<1 || $limit>100) {$this->error('limit须为1至100');return 1;}
        $query=$videos->query()->orderBy('id');$key=$this->option('key');
        if ($key && !preg_match('/^[A-Za-z0-9]{1,64}$/D',$key)) {$this->error('无效的视频编码');return 1;}
        if ($key) $query->where('key',$key);
        $done=0;$failed=0;$skipped=0;
        foreach($query->cursor() as $video) {
            if (!$this->option('force') && $videos->posterPath($video)) {$skipped++;continue;}
            try {$videos->prepare($video);$done++;$this->line(json_encode(['key'=>$video->key,'status'=>'prepared']));}
            catch(\Throwable $e) {$failed++;$this->warn('视频 '.$video->key.' 封面未生成，请检查文件、磁盘和探测组件');}
            if ($done+$failed >= $limit) break;
        }
        $this->line(json_encode(['prepared'=>$done,'skipped'=>$skipped,'failed'=>$failed]));return $failed?1:0;
    }
}
