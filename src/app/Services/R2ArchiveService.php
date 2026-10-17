<?php
namespace App\Services;

use App\Enums\StrategyKey;
use App\Models\Image;
use App\Models\R2ArchiveJob;
use App\Models\R2ArchivePolicy;
use App\Models\Strategy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use League\Flysystem\Filesystem;
use RuntimeException;

/** Copy -> verify every byte through the destination -> commit records -> delete source. */
class R2ArchiveService
{
    protected function gateway(Strategy $s): R2UploadGateway { return new R2UploadGateway($s); }
    protected function filesystem(Strategy $s): Filesystem { return new Filesystem(app(ImageService::class)->getAdapter($s)); }
    public function sourceIds(string $identity): array
    {
        return Strategy::all()->filter(fn ($s) => in_array($s->key, [StrategyKey::S3, StrategyKey::Minio])
            && R2ArchiveQuota::identity($s) === $identity)->modelKeys();
    }
    public function validate(R2ArchivePolicy $p): array
    {
        $source = Strategy::findOrFail($p->source_strategy_id);
        $target = Strategy::findOrFail($p->target_strategy_id);
        $host = parse_url((string)$source->configs->get('endpoint'), PHP_URL_HOST);
        if (!in_array($source->key, [StrategyKey::S3, StrategyKey::Minio])
            || !preg_match('/^[a-f0-9]{32}(?:\.(?:eu|fedramp))?\.r2\.cloudflarestorage\.com$/iD', (string)$host)
            || R2ArchiveQuota::identity($source) !== $p->source_identity) throw new RuntimeException('R2 归档源配置不匹配');
        if ($target->key !== StrategyKey::Telegram || R2ArchiveQuota::targetIdentity($target) !== $p->target_identity) {
            throw new RuntimeException('TG 归档目标配置已变化，请重新配置策略');
        }
        $api = (string)$target->configs->get('api_base');
        $root = (string)$target->configs->get('local_root');
        if (!$target->configs->get('bot_token') || !$target->configs->get('chat_id')) throw new RuntimeException('请先配置独立归档 Bot 和频道');
        if (!$api || strtolower((string)parse_url($api, PHP_URL_HOST)) === 'api.telegram.org'
            || !$root || $root === '/' || !is_dir($root) || !is_readable($root)) {
            throw new RuntimeException('完整大图归档需要自建 TG API 和可读的共享目录 local_root');
        }
        if (!$p->links_ready) throw new RuntimeException('必须先部署旧 R2 链接转发 Worker');
        return [$source, $target];
    }
    /** Bounded streaming validation; never decode an image or hold its bytes in PHP memory. */
    private function digest($stream, int $limit, $copy = null): array
    {
        $md5 = hash_init('md5'); $sha1 = hash_init('sha1'); $bytes = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 1024*1024);
                if ($chunk === false || ($chunk === '' && !feof($stream))) throw new RuntimeException('迁移文件读取失败');
                $bytes += strlen($chunk);
                if ($bytes > $limit) throw new RuntimeException('迁移文件超过预期长度');
                hash_update($md5, $chunk); hash_update($sha1, $chunk);
                if (is_resource($copy)) {
                    $offset = 0;
                    while ($offset < strlen($chunk)) {
                        $written = fwrite($copy, substr($chunk, $offset));
                        if (!$written) throw new RuntimeException('迁移暂存写入失败');
                        $offset += $written;
                    }
                }
            }
        } finally { if (is_resource($stream)) fclose($stream); }
        return ['bytes'=>$bytes, 'md5'=>hash_final($md5), 'sha1'=>hash_final($sha1)];
    }
    private function verify(array $digest, R2ArchiveJob $job): void
    {
        if ($digest['bytes'] !== $job->bytes || !hash_equals($job->md5, $digest['md5'])
            || !hash_equals($job->sha1, $digest['sha1'])) throw new RuntimeException('迁移文件长度或哈希校验失败，保留 R2 原文件');
    }
    private function sourceRows(R2ArchiveJob $job)
    {
        return Image::whereIn('strategy_id', $this->sourceIds($job->source_identity))
            ->whereIn('path', [dirname($job->source_path) === '.' ? '' : dirname($job->source_path), '/'.dirname($job->source_path)])
            ->where('name', basename($job->source_path));
    }
    public function migrate(R2ArchiveJob $job, Strategy $source, Strategy $target): void
    {
        if ($job->phase === 'done' || $job->phase === 'cancelled') return;
        if ($job->target_identity !== R2ArchiveQuota::targetIdentity($target)
            || $job->source_identity !== R2ArchiveQuota::identity($source)) throw new RuntimeException('迁移任务存储身份已变化');
        $remote = $this->gateway($source);
        $destination = $this->filesystem($target);
        $verified = false;
        if ($job->phase === 'pending') {
            if (!$this->sourceRows($job)->exists()) { $job->update(['phase'=>'cancelled']); return; }
            $head = $remote->head($job->source_path);
            if ((int)$head['ContentLength'] !== $job->bytes) throw new RuntimeException('R2 原文件长度已变化');
            if ($job->etag && $job->etag !== ($head['ETag'] ?? null)) throw new RuntimeException('R2 原文件已被其他写入替换');
            if (empty($head['ETag'])) throw new RuntimeException('R2 未返回对象 ETag');
            $job->update(['etag'=>$head['ETag']]);
            if (!$destination->fileExists($job->target_path)) {
                // R2 staging plus Local Bot API download cache must leave space for the running site.
                $required = 2*$job->bytes + 512*1024*1024;
                foreach ([sys_get_temp_dir(), (string)$target->configs->get('local_root')] as $root) {
                    $free = disk_free_space($root);
                    if ($free === false || $free < $required) throw new RuntimeException('迁移暂存或 TG 缓存磁盘空间不足，保留 R2 原文件');
                }
                $tmp = tmpfile();
                if (!$tmp) throw new RuntimeException('无法创建迁移暂存文件');
                try {
                    $this->verify($this->digest($remote->readStream($job->source_path), $job->bytes, $tmp), $job);
                    rewind($tmp);
                    $destination->writeStream($job->target_path, $tmp);
                } finally { if (is_resource($tmp)) fclose($tmp); }
            }
            // A successful sendDocument alone is insufficient: getFile and read the actual destination.
            $this->verify($this->digest($destination->readStream($job->target_path), $job->bytes), $job);
            $verified = true;
            $job->update(['phase'=>'copied', 'error'=>null]);
        }
        if ($job->phase === 'copied') {
            // Revalidate on resume before switching any image records.
            if (!$verified) $this->verify($this->digest($destination->readStream($job->target_path), $job->bytes), $job);
            $verified = true;
            $head = $remote->head($job->source_path);
            if (($head['ETag'] ?? null) !== $job->etag) throw new RuntimeException('R2 原文件在迁移期间发生变化');
            DB::transaction(function () use ($job, $target) {
                $rows = $this->sourceRows($job)->lockForUpdate()->get();
                foreach ($rows as $row) {
                    if ($row->md5 !== $job->md5 || $row->sha1 !== $job->sha1) throw new RuntimeException('同路径图片记录哈希不一致');
                    $row->forceFill(['strategy_id'=>$target->id, 'path'=>dirname($job->target_path),
                        'name'=>basename($job->target_path)])->save();
                }
                $job->update(['phase'=>$rows->isEmpty() ? 'cancelled' : 'switched', 'error'=>null]);
            });
        }
        if ($job->phase === 'switched') {
            if (!$verified) $this->verify($this->digest($destination->readStream($job->target_path), $job->bytes), $job);
            if ($this->sourceRows($job)->exists()) throw new RuntimeException('仍有图片记录使用 R2 原文件，暂不删除');
            $head = $remote->find($job->source_path);
            if ($head && ($head['ETag'] ?? null) !== $job->etag) throw new RuntimeException('R2 对象已变化，拒绝删除');
            if ($head) $remote->delete($job->source_path);
            $job->update(['phase'=>'done', 'error'=>null]);
        }
    }
    public function run(bool $dryRun = false, int $limit = 25): array
    {
        if (!Schema::hasTable('r2_archive_policies')) return [];
        $dir = storage_path('app/r2-archive');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('无法创建归档目录');
        $lock = fopen($dir.'/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX|LOCK_NB)) { if ($lock) fclose($lock); return ['busy'=>true]; }
        try {
            $reports = [];
            foreach (R2ArchivePolicy::where('enabled', true)->get() as $policy) {
                [$source, $target] = $this->validate($policy);
                $used = $this->gateway($source)->bucketBytes();
                $pending = app(R2ArchiveQuota::class)->pending($policy->source_identity);
                $report = ['policy'=>$policy->id, 'used'=>$used, 'reserved'=>$pending, 'migrated'=>0, 'failed'=>0];
                if ($dryRun) { $reports[]=$report; continue; }
                $unfinished = R2ArchiveJob::where('policy_id', $policy->id)->whereNotIn('phase', ['done','cancelled'])->orderBy('id')->limit($limit)->get();
                $attempts = 0;
                foreach ($unfinished as $job) {
                    $attempts++;
                    try { $this->migrate($job, $source, $target); if ($job->phase === 'done') $report['migrated']++; }
                    catch (\Throwable) { $job->update(['error'=>'归档未完成，保留未删除的源文件；请检查 TG 服务、磁盘与权限']); $report['failed']++; }
                }
                $used = $this->gateway($source)->bucketBytes();
                if ($used + $pending >= $policy->trigger_bytes || $policy->requested) {
                    // Persist the cycle across process limits and restarts, even below the trigger.
                    $policy->update(['requested'=>true]);
                    $images = Image::whereIn('strategy_id', $this->sourceIds($policy->source_identity))->orderBy('id')->cursor();
                    foreach ($images as $image) {
                        if ($attempts >= $limit || $used + $pending <= $policy->target_bytes) break;
                        $sourcePath = ltrim($image->pathname, '/');
                        $hash = hash('sha256', $sourcePath);
                        if (R2ArchiveJob::where('source_identity', $policy->source_identity)->where('source_path_hash', $hash)->exists()) continue;
                        $bytes = (int)round($image->size*1024);
                        if ($bytes > 512*1024*1024 || $bytes < 1) continue;
                        $job = R2ArchiveJob::create(['policy_id'=>$policy->id, 'image_id'=>$image->id,
                            'source_identity'=>$policy->source_identity, 'source_path_hash'=>$hash,
                            'source_path'=>$sourcePath, 'source_strategy_id'=>$source->id,
                            'target_strategy_id'=>$target->id, 'target_identity'=>$policy->target_identity,
                            'target_path'=>'r2-archive/'.$policy->source_identity.'/'.$image->key.'.'.$image->extension,
                            'bytes'=>$bytes, 'md5'=>$image->md5, 'sha1'=>$image->sha1]);
                        $attempts++;
                        try { $this->migrate($job, $source, $target); if ($job->phase === 'done') $report['migrated']++; }
                        catch (\Throwable) { $job->update(['error'=>'归档未完成，保留未删除的源文件；请检查 TG 服务、磁盘与权限']); $report['failed']++; }
                        $used = $this->gateway($source)->bucketBytes();
                    }
                    // Zero means drain managed images; unrelated bucket objects must remain untouched.
                    $complete = $policy->target_bytes === 0
                        ? $pending === 0
                            && !Image::whereIn('strategy_id', $this->sourceIds($policy->source_identity))->exists()
                            && !R2ArchiveJob::where('policy_id', $policy->id)->whereNotIn('phase', ['done','cancelled'])->exists()
                        : $used + $pending <= $policy->target_bytes;
                    if ($complete) $policy->update(['requested'=>false]);
                }
                $report['used_after']=$used; $reports[]=$report;
            }
            return $reports;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
