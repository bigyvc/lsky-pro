<?php
namespace App\Services;

/** Coordinates registered getFile downloads with the host cleaner, never TDLib state. */
class TelegramCacheRegistry
{
    public static function lock()
    {
        $dir = storage_path('app/tg-cache');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('无法创建 TG 缓存登记目录');
        $lock = fopen($dir.'/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_SH)) throw new \RuntimeException('无法锁定 TG 缓存');
        return $lock;
    }
    public static function register(string $root, string $file): void
    {
        $relative = substr($file, strlen(rtrim($root, '/')) + 1);
        // Only Bot API's completed media downloads, not databases, temp or root files.
        $parent = basename(dirname($relative));
        if (!in_array($parent, ['documents','photos','videos','animations','audio','voice','video_notes','stickers'], true)) return;
        $stat = stat($file);
        if (!$stat) return;
        $dir = storage_path('app/tg-cache');
        $name = hash('sha256', $root.'|'.$relative);
        $tmp = $dir.'/'.$name.'.'.bin2hex(random_bytes(8)).'.tmp';
        $data = ['root'=>$root, 'relative'=>$relative, 'device'=>$stat['dev'], 'inode'=>$stat['ino'],
            'size'=>$stat['size'], 'mtime'=>$stat['mtime'], 'accessed'=>time()];
        if (file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR)) === false) throw new \RuntimeException('无法登记 TG 缓存');
        chmod($tmp, 0600);
        if (!rename($tmp, $dir.'/'.$name.'.json')) { @unlink($tmp); throw new \RuntimeException('无法保存 TG 缓存登记'); }
    }
}
