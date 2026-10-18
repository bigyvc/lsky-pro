<?php
namespace App\Console\Commands;

use App\Services\GalleryPosts;
use App\Services\GallerySettings;
use Illuminate\Console\Command;

class SyncGallery extends Command
{
    protected $signature='gallery:sync';
    protected $description='Backfill complete photo groups from existing import ledgers; no media downloads';

    public function handle(GallerySettings $settings, GalleryPosts $posts): int
    {
        $s=$settings->read();
        if (!$s['user_id'] || !$s['album_id']) {$this->error('先用gallery:configure选择账号与相册');return 1;}
        $dir=storage_path('app/tg-photo-import');$ok=0;$skip=0;$failed=0;
        if (!is_dir($dir)) {$this->info('没有旧导入状态，新照片组将自动建立索引');return 0;}
        foreach (new \FilesystemIterator($dir,\FilesystemIterator::SKIP_DOTS) as $file) {
            if (!$file->isFile() || $file->isLink() || !preg_match('/^[a-f0-9]{64}\.json$/D',$file->getFilename())) continue;
            try {
                $ledger=json_decode(file_get_contents($file->getPathname()),true,512,JSON_THROW_ON_ERROR);
                if ($posts->fromLedger($ledger,$s['user_id'],$s['album_id'])) $ok++;else $skip++;
            } catch (\Throwable $e) {$failed++;}
        }
        $this->line(json_encode(['indexed'=>$ok,'skipped'=>$skip,'failed'=>$failed]));
        return $failed ? 1 : 0;
    }
}
