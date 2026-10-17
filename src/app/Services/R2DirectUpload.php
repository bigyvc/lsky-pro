<?php

namespace App\Services;

use App\Enums\ConfigKey;
use App\Enums\GroupConfigKey;
use App\Enums\Strategy\S3Option;
use App\Enums\StrategyKey;
use App\Enums\UserStatus;
use App\Exceptions\UploadException;
use App\Models\Image;
use App\Models\Strategy;
use App\Utils;
use Illuminate\Http\Request;
use RuntimeException;

/** Administrator-only, resumable R2 multipart sessions. Credentials never enter browser responses. */
class R2DirectUpload
{
    public const CHUNK_SIZE = 50 * 1024 * 1024;
    public const CONCURRENCY = 6;
    public const TTL = 86400;

    public function __construct(private ImageService $images) {}

    protected function root(): string { return storage_path('app/r2-direct'); }
    protected function gateway(Strategy $strategy): R2UploadGateway { return new R2UploadGateway($strategy); }

    private function directory(int $userId, string $id = ''): string
    {
        if ($id && ! preg_match('/^[a-f0-9]{32}$/D', $id)) throw new UploadException('上传任务编号无效');
        return $this->root().'/'.hash('sha256', 'user:'.$userId).($id ? '/'.$id : '');
    }

    private function mkdir(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) throw new RuntimeException('创建直传任务目录失败');
    }

    private function lock(string $path, callable $callback, bool $nonblocking = false): mixed
    {
        $handle = @fopen($path, 'c');
        if (! $handle) throw new RuntimeException('无法锁定直传任务');
        if (! flock($handle, LOCK_EX | ($nonblocking ? LOCK_NB : 0))) {
            fclose($handle);
            if ($nonblocking) return null;
            throw new RuntimeException('无法锁定直传任务');
        }
        try { return $callback(); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function read(string $dir): array
    {
        $meta = json_decode((string) @file_get_contents($dir.'/meta.json'), true);
        if (! is_array($meta) || ($meta['updated_at'] ?? 0) < time() - self::TTL) throw new UploadException('直传任务不存在或已过期');
        if (($meta['phase'] ?? '') === 'aborted') throw new UploadException('直传任务已取消或文件校验失败，请重新选择文件');
        return $meta;
    }

    private function save(string $dir, array $meta): void
    {
        $meta['updated_at'] = time();
        if (file_put_contents($dir.'/meta.tmp', json_encode($meta, JSON_THROW_ON_ERROR)) === false
            || ! rename($dir.'/meta.tmp', $dir.'/meta.json')) throw new RuntimeException('保存直传状态失败');
    }

    private function identity(Strategy $strategy): string
    {
        return hash('sha256', $strategy->configs->get(S3Option::Endpoint).'|'.$strategy->configs->get(S3Option::Bucket));
    }

    private function access(Request $request, int $strategyId, array $file): Strategy
    {
        $user = $request->user();
        if (! $user || ! $user->is_adminer) throw new UploadException('R2 直传仅对管理员开放');
        if ($user->status !== UserStatus::Normal || (Utils::config(ConfigKey::IsUserNeedVerify) && ! $user->email_verified_at)) {
            throw new UploadException('账号异常或尚未验证');
        }
        $group = $user->group;
        $strategy = $group?->strategies()->find($strategyId);
        if (! $strategy || ! in_array($strategy->key, [StrategyKey::S3, StrategyKey::Minio], true)) throw new UploadException('请先选择管理员组可用的 R2 存储策略');
        $endpoint = $strategy->configs->get(S3Option::Endpoint);
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https'
            || ! preg_match('/^[a-f0-9]{32}(?:\.(?:eu|fedramp))?\.r2\.cloudflarestorage\.com$/iD', (string) $host)) {
            throw new UploadException('当前策略不是标准 R2 S3 接口，请检查存储策略配置');
        }
        $configs = $group->configs;
        if ($configs->get(GroupConfigKey::IsEnableScan) || $configs->get(GroupConfigKey::IsEnableWatermark)
            || $configs->get(GroupConfigKey::ImageSaveFormat) || $configs->get(GroupConfigKey::ImageSaveQuality, 100) < 100) {
            throw new UploadException('当前角色组要求图片处理或审查，无法使用此上传通道，请联系管理员检查策略');
        }
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (! in_array($extension, $configs->get(GroupConfigKey::AcceptedFileSuffixes, []))) throw new UploadException('不支持的文件类型');
        if (VideoMetadata::isMedia($extension) && $file['size'] > ($extension === 'm3u8' ? 1048576 : 512*1024*1024)) {
            throw new UploadException($extension === 'm3u8' ? 'M3U8播放列表不能超过1MiB' : '音视频不能超过512MiB');
        }
        if ($file['size'] / 1024 > $configs->get(GroupConfigKey::MaximumFileSize)) throw new UploadException('图片大小超出限制');
        return $strategy;
    }

    private function mime(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (VideoMetadata::isMedia($extension)) return VideoMetadata::mime($extension);
        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'bmp' => 'image/bmp', 'tif', 'tiff' => 'image/tiff',
            'ico' => 'image/vnd.microsoft.icon', 'psd' => 'image/vnd.adobe.photoshop',
            default => throw new UploadException('该图片格式暂不支持此上传通道'),
        };
    }

    private function pendingBytes(int $userId): array
    {
        $bytes = 0; $count = 0; $large = 0;
        $hash = hash('sha256', 'user:'.$userId);
        foreach ([$this->root().'/'.$hash, storage_path('app/upload-chunks/'.$hash)] as $base) {
            foreach (glob($base.'/*/meta.json') ?: [] as $path) {
                $meta = json_decode((string) file_get_contents($path), true);
                if (($meta['updated_at'] ?? 0) >= time() - self::TTL && ! isset($meta['result']) && ($meta['phase'] ?? '') !== 'aborted') {
                    $bytes += $meta['file']['size']; $count++;
                    if ($meta['file']['size'] > self::CHUNK_SIZE) $large++;
                }
            }
        }
        return [$bytes, $count, $large];
    }

    private function checkedParts(array $meta, R2UploadGateway $gateway): array
    {
        $parts = $gateway->parts($meta['key'], $meta['upload_id']);
        usort($parts, fn ($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);
        foreach ($parts as $part) {
            $index = (int) $part['PartNumber'] - 1;
            if (! isset($meta['file']['parts'][$index]) || (int) $part['Size'] !== $meta['file']['parts'][$index]['size']
                || ! hash_equals($meta['file']['parts'][$index]['md5'], strtolower(trim($part['ETag'], '"')))) {
                throw new UploadException('R2 分片长度或校验值不匹配，请取消后重新上传');
            }
        }
        return $parts;
    }

    private function state(string $dir, array $meta, Strategy $strategy): array
    {
        if (isset($meta['result'])) return ['id' => basename($dir), 'result' => $meta['result']];
        $gateway = $this->gateway($strategy);
        $received = [];
        $signatures = [];
        if ($meta['phase'] === 'uploading') {
            $meta['quota_lease']=app(R2ArchiveQuota::class)->reserve($strategy, $meta['file']['size'], basename($dir), $meta['key']);
            $this->save($dir, $meta);
            $received = array_map(fn ($p) => (int) $p['PartNumber'] - 1, $this->checkedParts($meta, $gateway));
            foreach ($meta['file']['parts'] as $index => $part) {
                if (! in_array($index, $received)) $signatures[$index] = $gateway->sign($meta['key'], $meta['upload_id'], $index + 1, $part['size'], $part['md5']);
            }
        }
        return ['id' => basename($dir), 'chunk_size' => self::CHUNK_SIZE, 'concurrency' => self::CONCURRENCY,
            'received' => $received, 'parts' => (object) $signatures, 'uploaded' => $meta['phase'] !== 'uploading', 'result' => null];
    }

    private function availableKey(Strategy $strategy, string $preferred): string
    {
        $identity = $this->identity($strategy);
        $reserved = app(R2ArchiveQuota::class)->pendingKeys(R2ArchiveQuota::identity($strategy));
        // Include other administrators and expired tasks until pruning has aborted them remotely.
        foreach (glob($this->root().'/*/*/meta.json') ?: [] as $path) {
            $meta = json_decode((string) file_get_contents($path), true);
            if (($meta['storage_identity'] ?? '') === $identity && ($meta['phase'] ?? '') !== 'aborted') {
                $reserved[$meta['key']] = true;
            }
        }
        $strategyIds = Strategy::all()->filter(fn ($s) => $this->identity($s) === $identity)->modelKeys();
        $gateway = $this->gateway($strategy);
        $extension = pathinfo($preferred, PATHINFO_EXTENSION);
        $stem = substr($preferred, 0, -strlen($extension) - 1);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $key = $attempt === 0 ? $preferred : $stem.'-'.bin2hex(random_bytes(3)).'.'.$extension;
            if (isset($reserved[$key])) continue;
            if (\Illuminate\Support\Facades\Schema::hasTable('r2_archive_jobs') && \App\Models\R2ArchiveJob::where('source_identity', R2ArchiveQuota::identity($strategy))
                ->where('source_path_hash', hash('sha256', $key))->exists()) continue;
            if (Image::query()->whereIn('strategy_id', $strategyIds)
                ->where('path', dirname($key) === '.' ? '' : dirname($key))->where('name', basename($key))->exists()) continue;
            if ($gateway->find($key) !== null) continue;
            return $key;
        }
        throw new UploadException('无法分配未重名的文件名，请稍后重试');
    }

    public function begin(Request $request, array $file, int $strategyId, ?string $id): array
    {
        $strategy = $this->access($request, $strategyId, $file);
        $file['strategy_id'] = $strategy->id;
        $user = $request->user();
        $base = $this->directory($user->id);
        $this->mkdir($base);
        return $this->lock($base.'/.owner.lock', function () use ($request, $strategy, $file, $user, $id) {
            // Recover an init response lost before the browser learned its task ID.
            if (! $id) {
                foreach (glob($this->directory($user->id).'/*/meta.json') ?: [] as $path) {
                    $candidate = json_decode((string) file_get_contents($path), true);
                    if (($candidate['updated_at'] ?? 0) >= time() - self::TTL && ($candidate['phase'] ?? '') !== 'aborted'
                        && ! isset($candidate['result']) && ($candidate['file'] ?? null) === $file
                        && ($candidate['storage_identity'] ?? '') === $this->identity($strategy)) {
                        $id = basename(dirname($path)); break;
                    }
                }
            }
            if ($id && is_file($this->directory($user->id, $id).'/meta.json')) {
                $dir = $this->directory($user->id, $id);
                return $this->lock($dir.'/.lock', function () use ($dir, $file, $strategy) {
                    $meta = $this->read($dir);
                    if ($meta['file'] !== $file || $meta['storage_identity'] !== $this->identity($strategy)) throw new UploadException('续传文件或存储策略与原任务不一致');
                    $this->save($dir, $meta);
                    return $this->state($dir, $meta, $strategy);
                });
            }
            [$pending, $count, $large] = $this->pendingBytes($user->id);
            if (($file['size'] > self::CHUNK_SIZE && $large >= 3) || $count >= 1000) throw new UploadException('请先完成已有的上传任务');
            if (($pending + $file['size']) / 1024 + $user->images()->sum('size') > $user->capacity) throw new UploadException('储存空间不足（包含待完成上传的预留容量）');
            $id = bin2hex(random_bytes(16));
            $dir = $this->directory($user->id, $id);
            $this->mkdir($dir);
            $preferred = $this->images->directPathname($user->group, $file['name']);
            // Reserve under a bucket-wide local lock before releasing it to the next initializer.
            $meta = $this->lock($this->root().'/.names.lock', function () use ($user, $file, $strategy, $preferred, $dir) {
                $meta = ['user_id' => $user->id, 'file' => $file, 'storage_identity' => $this->identity($strategy),
                    'key' => $this->availableKey($strategy, $preferred), 'phase' => 'creating'];
                $meta['quota_lease'] = app(R2ArchiveQuota::class)->reserve($strategy, $file['size'], basename($dir), $meta['key']);
                try { $this->save($dir, $meta); }
                catch (\Throwable $e) { app(R2ArchiveQuota::class)->release($meta['quota_lease']); throw $e; }
                return $meta;
            });
            return $this->lock($dir.'/.lock', function () use ($dir, $meta, $strategy, $id) {
                try { $meta['upload_id'] = $this->gateway($strategy)->start($meta['key'], $this->mime($meta['file']['name']), $id); }
                catch (\Throwable $e) {
                    // No upload ID was returned. Release the local reservation; bucket lifecycle covers an uncertain remote init.
                    $meta['phase'] = 'aborted'; $this->save($dir, $meta); app(R2ArchiveQuota::class)->release($meta['quota_lease'] ?? null); throw $e;
                }
                $meta['phase'] = 'uploading';
                $this->save($dir, $meta);
                return $this->state($dir, $meta, $strategy);
            });
        });
    }

    private function task(Request $request, string $id, callable $callback): mixed
    {
        if (! $request->user()?->is_adminer) throw new UploadException('R2 直传仅对管理员开放');
        $dir = $this->directory($request->user()->id, $id);
        if (! is_dir($dir)) throw new UploadException('直传任务不存在或已过期');
        return $this->lock($dir.'/.lock', function () use ($request, $dir, $callback) {
            $meta = $this->read($dir);
            $strategy = $this->access($request, $meta['file']['strategy_id'], $meta['file']);
            if ($meta['storage_identity'] !== $this->identity($strategy)) throw new UploadException('R2 存储配置已变化，请使用原配置取消任务');
            return $callback($dir, $meta, $strategy, $this->gateway($strategy));
        });
    }

    public function sign(Request $request, string $id, int $index): array
    {
        return $this->task($request, $id, function ($dir, $meta, $strategy, $gateway) use ($index) {
            if ($meta['phase'] !== 'uploading' || ! isset($meta['file']['parts'][$index])) throw new UploadException('分片或上传状态无效');
            $part = $meta['file']['parts'][$index];
            $meta['quota_lease']=app(R2ArchiveQuota::class)->reserve($strategy, $meta['file']['size'], basename($dir), $meta['key']);
            $this->save($dir, $meta);
            return $gateway->sign($meta['key'], $meta['upload_id'], $index + 1, $part['size'], $part['md5']);
        });
    }

    private function objectValid(array $head, array $meta, string $id): bool
    {
        return (int) ($head['ContentLength'] ?? -1) === $meta['file']['size']
            && ($head['Metadata']['lsky-direct-id'] ?? '') === $id;
    }

    public function complete(Request $request, string $id): array
    {
        return $this->task($request, $id, function ($dir, $meta, $strategy, $gateway) use ($request, $id) {
            if (isset($meta['result'])) { app(R2ArchiveQuota::class)->release($meta['quota_lease'] ?? null); return $meta['result']; }
            if ($meta['phase'] === 'creating') throw new UploadException('R2 任务创建结果未知，请取消任务后重试');
            if ($meta['phase'] === 'uploading' || $meta['phase'] === 'completing') {
                // Recover a lost CompleteMultipartUpload response without uploading the file a second time.
                $head = $gateway->find($meta['key']);
                if ($head && ! $this->objectValid($head, $meta, $id)) {
                    // An external writer used the reserved name. Never overwrite or delete its object.
                    $gateway->abort($meta['key'], $meta['upload_id']);
                    $meta['phase'] = 'aborted'; $this->save($dir, $meta);
                    app(R2ArchiveQuota::class)->release($meta['quota_lease'] ?? null);
                    throw new UploadException('文件名已被其他上传占用，请重新上传以自动分配短编号');
                }
                if (! $head || ! $this->objectValid($head, $meta, $id)) {
                    $parts = $this->checkedParts($meta, $gateway);
                    if (count($parts) !== count($meta['file']['parts'])) throw new UploadException('分片尚未完整上传');
                    $meta['phase'] = 'completing';
                    $this->save($dir, $meta);
                    $gateway->complete($meta['key'], $meta['upload_id'], $parts);
                }
                $meta['phase'] = 'object';
                $this->save($dir, $meta);
            }
            $head = $gateway->head($meta['key']);
            if (! $this->objectValid($head, $meta, $id)) throw new UploadException('R2 文件长度或所属任务校验失败');
            if (VideoMetadata::isMedia(pathinfo($meta['file']['name'], PATHINFO_EXTENSION))) {
                // Signed HTTPS GET: ffprobe can seek to MP4/MOV metadata without PHP buffering the video.
                $dimensions = $gateway->videoMetadata($meta['key'], $meta['file']['name']);
            } else {
                // Fast image path, bounded camera metadata fallback.
                $dimensions = @getimagesizefromstring($gateway->header($meta['key']));
                if (!$dimensions && $this->mime($meta['file']['name']) === 'image/jpeg') {
                    $dimensions = @getimagesizefromstring($gateway->header($meta['key'], min($meta['file']['size'], 4 * 1024 * 1024)));
                }
            }
            if (! $dimensions || ($dimensions['mime'] ?? '') !== $this->mime($meta['file']['name'])) {
                $gateway->delete($meta['key']);
                $meta['phase'] = 'aborted';
                $this->save($dir, $meta);
                app(R2ArchiveQuota::class)->release($meta['quota_lease'] ?? null);
                throw new UploadException('无法确认图片格式，请检查文件内容与扩展名是否一致');
            }
            $image = $this->images->registerDirect($request, $strategy, $meta['file'], $meta['key'], $dimensions);
            $meta['result'] = ['status' => true, 'message' => 'R2 直传成功', 'data' => $image
                ->setAppends(['pathname', 'links'])->only('id', 'pathname', 'origin_name', 'size', 'mimetype', 'md5', 'sha1', 'links')];
            $meta['phase'] = 'recorded';
            $this->save($dir, $meta);
            app(R2ArchiveQuota::class)->release($meta['quota_lease'] ?? null);
            return $meta['result'];
        });
    }

    public function abort(Request $request, string $id): void
    {
        $this->task($request, $id, function ($dir, $meta, $strategy, $gateway) {
            if (isset($meta['result'])) return;
            $this->discard($meta, $gateway, basename($dir));
            $meta['phase'] = 'aborted';
            $this->save($dir, $meta);
            app(R2ArchiveQuota::class)->release($meta['quota_lease'] ?? null);
        });
    }

    private function discard(array $meta, R2UploadGateway $gateway, string $id): void
    {
        // Protect a committed image when PHP died between DB commit and saving the cached result.
        if (Image::query()->where('strategy_id', $meta['file']['strategy_id'])
            ->where('path', dirname($meta['key']) === '.' ? '' : dirname($meta['key']))->where('name', basename($meta['key']))->exists()) return;
        if (isset($meta['upload_id'])) {
            try { $gateway->abort($meta['key'], $meta['upload_id']); }
            catch (\Aws\S3\Exception\S3Exception $e) { if ($e->getAwsErrorCode() !== 'NoSuchUpload') throw $e; }
        }
        if (in_array($meta['phase'], ['completing', 'object'])) {
            $head = $gateway->find($meta['key']);
            if ($head && ($head['Metadata']['lsky-direct-id'] ?? '') === $id) $gateway->delete($meta['key']);
        }
    }

    public function prune(): void
    {
        foreach (glob($this->root().'/*/*/meta.json') ?: [] as $path) {
            $dir = dirname($path);
            $this->lock($dir.'/.lock', function () use ($dir, $path) {
                $meta = json_decode((string) file_get_contents($path), true);
                if (($meta['updated_at'] ?? 0) >= time() - self::TTL) return;
                if (! isset($meta['result']) && ($meta['phase'] ?? '') !== 'aborted') {
                    $strategy = Strategy::find($meta['file']['strategy_id']);
                    if (! $strategy || $this->identity($strategy) !== $meta['storage_identity']) return;
                    try { $this->discard($meta, $this->gateway($strategy), basename($dir)); }
                    catch (\Throwable $e) { Utils::e($e, '过期 R2 直传任务清理失败'); return; }
                }
                app(R2ArchiveQuota::class)->release($meta['quota_lease'] ?? null);
                @unlink($path);
                @unlink($dir.'/meta.tmp');
                // Keep the lock inode while other requests might hold its file descriptor.
            }, true);
        }
    }
}
