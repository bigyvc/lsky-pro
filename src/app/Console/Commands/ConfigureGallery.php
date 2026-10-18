<?php
namespace App\Console\Commands;

use App\Models\User;
use App\Services\GallerySettings;
use Illuminate\Console\Command;

class ConfigureGallery extends Command
{
    protected $signature='gallery:configure {--user= : 图床账号邮箱} {--album=频道图片} {--host=dayv.org} {--title=DAYV · 影像之间} {--video-album= : 公开的视频相册名称} {--disable-videos : 关闭视频栏目公开内容} {--enable} {--disable}';
    protected $description='Explicitly publish one owned album on an independent gallery domain';

    public function handle(GallerySettings $settings): int
    {
        try {
            if ($this->option('enable') && $this->option('disable')) throw new \RuntimeException('enable与disable不能同时使用');
            if ($this->option('disable')) {
                $s=$settings->read();$s['enabled']=false;$settings->write($s);
                $this->info('图库已关闭，图床及采集不受影响');return 0;
            }
            if (!$this->option('enable')) {
                $this->line(json_encode($settings->read(),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));return 0;
            }
            if (!\Illuminate\Support\Facades\Schema::hasTable('gallery_posts') || !\Illuminate\Support\Facades\Schema::hasTable('gallery_post_images') || !\Illuminate\Support\Facades\Schema::hasTable('gallery_site_settings')) throw new \RuntimeException('图库迁移尚未完成，请先检查migrate:status');
            $user=User::where('email',$this->option('user'))->firstOrFail();
            $albums=$user->albums()->where('name',$this->option('album'))->get();
            if ($albums->count()!==1) throw new \RuntimeException('相册不存在或重名，请先在账号内保留唯一名称');
            $title=trim($this->option('title'));
            if (!$title || mb_strlen($title)>80) throw new \RuntimeException('站点名称须为1至80字');
            $previous=$settings->read();
            $videoAlbumId=$previous['user_id']===$user->id ? (int)($previous['video_album_id']??0) : 0;
            if ($this->option('video-album') && $this->option('disable-videos')) throw new \RuntimeException('视频相册与disable-videos不能同时使用');
            if ($this->option('disable-videos')) $videoAlbumId=0;
            if ($name=$this->option('video-album')) {
                $videoAlbums=$user->albums()->where('name',$name)->get();
                if ($videoAlbums->count()!==1) throw new \RuntimeException('视频相册不存在或重名，请先在账号内创建唯一名称的相册');
                $videoAlbumId=$videoAlbums[0]->id;
            }
            if ($videoAlbumId===$albums[0]->id) throw new \RuntimeException('请为视频选择独立相册');
            $settings->write(['video_album_id'=>$videoAlbumId,'enabled'=>true,'host'=>strtolower($this->option('host')),'title'=>$title,'user_id'=>$user->id,'album_id'=>$albums[0]->id]);
            $this->info('已公开所选相册内的采集照片组：https://'.$this->option('host').'；所选视频相册内的视频也会公开（如已配置），其他相册及游客上传不公开');
            return 0;
        } catch (\Throwable $e) {$this->error($e instanceof \RuntimeException ? $e->getMessage() : '配置失败，请核对账号、相册与文件权限');return 1;}
    }
}
