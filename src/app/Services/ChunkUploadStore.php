<?php

namespace App\Services;

use RuntimeException;

/** Private, disk-backed staging. Never concatenate image bytes in PHP memory. */
class ChunkUploadStore
{
    public const CHUNK_SIZE = 50 * 1024 * 1024;
    public const TTL = 86400;

    public function __construct(private string $root) {}

    private function ownerPath(string $owner): string
    {
        return $this->root.'/'.hash('sha256', $owner);
    }

    private function directory(string $owner, string $id): string
    {
        if (! preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new RuntimeException('上传任务编号无效');
        }
        return $this->ownerPath($owner).'/'.$id;
    }

    private function mkdir(string $path): void
    {
        if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('无法创建分片目录，请检查磁盘和权限');
        }
    }

    private function locked(string $path, callable $callback): mixed
    {
        $lock = @fopen($path, 'c');
        if (! $lock || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('无法锁定上传任务');
        }
        try { return $callback(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function read(string $dir): array
    {
        $meta = json_decode((string) @file_get_contents($dir.'/meta.json'), true);
        if (! is_array($meta) || ($meta['updated_at'] ?? 0) < time() - self::TTL) {
            throw new RuntimeException('上传任务不存在或已过期，请重新选择文件');
        }
        return $meta;
    }

    private function save(string $dir, array $meta): void
    {
        $meta['updated_at'] = time();
        $tmp = $dir.'/meta.tmp';
        if (file_put_contents($tmp, json_encode($meta, JSON_THROW_ON_ERROR)) === false
            || ! rename($tmp, $dir.'/meta.json')) {
            throw new RuntimeException('保存上传状态失败，请检查磁盘空间');
        }
    }

    private function state(string $dir, array $meta): array
    {
        $received = [];
        for ($i = 0; $i < $meta['count']; $i++) {
            if (is_file($dir.'/'.$i.'.part')) $received[] = $i;
        }
        return ['id' => basename($dir), 'chunk_size' => self::CHUNK_SIZE,
            'received' => $received, 'result' => $meta['result'] ?? null];
    }

    /** reserve receives bytes already staged by this owner, under an owner-wide lock. */
    public function begin(string $owner, array $metadata, ?string $id, callable $reserve): array
    {
        $base = $this->ownerPath($owner);
        $this->mkdir($base);
        return $this->locked($base.'/.owner.lock', function () use ($base, $owner, $metadata, $id, $reserve) {
            if ($id) {
                $dir = $this->directory($owner, $id);
                if (is_file($dir.'/meta.json')) {
                    return $this->locked($dir.'/.lock', function () use ($dir, $metadata) {
                        $meta = $this->read($dir);
                        if ($meta['file'] !== $metadata) throw new RuntimeException('续传文件与原任务不一致');
                        $this->save($dir, $meta);
                        return $this->state($dir, $meta);
                    });
                }
            }
            $pending = 0;
            $count = 0;
            $tasks = 0;
            foreach (glob($base.'/*/meta.json') ?: [] as $path) {
                $meta = json_decode((string) file_get_contents($path), true);
                if (($meta['updated_at'] ?? 0) < time() - self::TTL) {
                    $this->removeExpired(dirname($path));
                } elseif (! isset($meta['result'])) {
                    $pending += $meta['file']['size'];
                    $tasks++;
                    if ($meta['file']['size'] > self::CHUNK_SIZE) $count++;
                }
            }
            if (($metadata['size'] > self::CHUNK_SIZE && $count >= 3) || $tasks >= 1000 || $pending + $metadata['size'] > 1536 * 1024 * 1024) {
                throw new RuntimeException('待完成上传任务或预留容量过多，请先完成已有任务');
            }
            $reserve($pending);
            $dir = $base.'/'.bin2hex(random_bytes(16));
            $this->mkdir($dir);
            $meta = ['file' => $metadata, 'count' => (int) ceil($metadata['size'] / self::CHUNK_SIZE)];
            $this->save($dir, $meta);
            return $this->state($dir, $meta);
        });
    }

    public function put(string $owner, string $id, int $index, string $source): array
    {
        $dir = $this->directory($owner, $id);
        if (! is_dir($dir)) throw new RuntimeException('上传任务不存在');
        return $this->locked($dir.'/.lock', function () use ($dir, $index, $source) {
            $meta = $this->read($dir);
            if ($index < 0 || $index >= $meta['count']) throw new RuntimeException('分片序号无效');
            if (isset($meta['result'])) return $this->state($dir, $meta);
            $expected = min(self::CHUNK_SIZE, $meta['file']['size'] - $index * self::CHUNK_SIZE);
            if (filesize($source) !== $expected) throw new RuntimeException('分片大小不匹配');
            $part = $dir.'/'.$index.'.part';
            // A repeated request is idempotent, but different bytes cannot overwrite an accepted part.
            if (is_file($part)) {
                if (! hash_equals(hash_file('sha256', $part), hash_file('sha256', $source))) {
                    throw new RuntimeException('分片内容冲突，请重新选择文件');
                }
            } else {
                $in = fopen($source, 'rb');
                $out = fopen($part.'.tmp', 'wb');
                if (! $in || ! $out) {
                    if (is_resource($in)) fclose($in);
                    if (is_resource($out)) fclose($out);
                    throw new RuntimeException('无法写入分片');
                }
                try { $copied = stream_copy_to_stream($in, $out); }
                finally { fclose($in); fclose($out); }
                if ($copied !== $expected || ! rename($part.'.tmp', $part)) {
                    @unlink($part.'.tmp');
                    throw new RuntimeException('分片写入不完整，请检查磁盘空间');
                }
            }
            $this->save($dir, $meta);
            return $this->state($dir, $meta);
        });
    }

    public function complete(string $owner, string $id, callable $store): array
    {
        $dir = $this->directory($owner, $id);
        if (! is_dir($dir)) throw new RuntimeException('上传任务不存在');
        return $this->locked($dir.'/.lock', function () use ($dir, $store) {
            $meta = $this->read($dir);
            if (isset($meta['result'])) return $meta['result'];
            // Persist intent before external writes. An uncertain commit must not be retried automatically.
            if (isset($meta['committing'])) {
                throw new RuntimeException('任务提交结果尚未确认，请先检查我的图片，避免重复上传');
            }
            for ($i = 0; $i < $meta['count']; $i++) {
                $expected = min(self::CHUNK_SIZE, $meta['file']['size'] - $i * self::CHUNK_SIZE);
                if (! is_file($dir.'/'.$i.'.part') || filesize($dir.'/'.$i.'.part') !== $expected) {
                    throw new RuntimeException('分片尚未完整上传，请继续上传');
                }
            }
            $merged = $dir.'/assembled';
            $out = fopen($merged, 'wb');
            if (! $out) throw new RuntimeException('无法创建合并文件');
            try {
                for ($i = 0; $i < $meta['count']; $i++) {
                    $in = fopen($dir.'/'.$i.'.part', 'rb');
                    try {
                        if (stream_copy_to_stream($in, $out) !== filesize($dir.'/'.$i.'.part')) {
                            throw new RuntimeException('合并文件失败，请检查磁盘空间');
                        }
                    } finally { fclose($in); }
                }
            } finally { fclose($out); }
            clearstatcache(true, $merged);
            if (filesize($merged) !== $meta['file']['size']) throw new RuntimeException('合并后的文件大小不匹配');
            $meta['committing'] = true;
            $this->save($dir, $meta);
            try {
                $result = $store($merged, $meta['file']);
                $meta['result'] = $result;
                unset($meta['committing']);
                $this->save($dir, $meta);
                foreach (glob($dir.'/*.part') ?: [] as $part) @unlink($part);
                return $result;
            } finally { @unlink($merged); }
        });
    }

    private function removeExpired(string $dir): void
    {
        $lock = @fopen($dir.'/.lock', 'c');
        if (! $lock) return;
        if (! flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return; }
        try {
            $meta = json_decode((string) @file_get_contents($dir.'/meta.json'), true);
            if (($meta['updated_at'] ?? 0) >= time() - self::TTL) return;
            foreach (glob($dir.'/*') ?: [] as $path) if (is_file($path)) @unlink($path);
            @unlink($dir.'/.lock');
            @rmdir($dir);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function prune(): void
    {
        foreach (glob($this->root.'/*/*/meta.json') ?: [] as $path) $this->removeExpired(dirname($path));
    }
}
