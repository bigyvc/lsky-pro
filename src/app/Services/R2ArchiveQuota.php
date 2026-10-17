<?php
namespace App\Services;

use App\Exceptions\UploadException;
use App\Models\R2ArchivePolicy;
use App\Models\Strategy;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Reservations coordinate relay uploads, browser multipart uploads and the archive worker. */
class R2ArchiveQuota
{
    public static function identity(Strategy $s): string
    {
        return hash('sha256', rtrim((string)$s->configs->get('endpoint'), '/').'|'.$s->configs->get('bucket'));
    }
    public static function targetIdentity(Strategy $s): string
    {
        return hash('sha256', json_encode([$s->configs->get('bot_token'), $s->configs->get('chat_id'),
            rtrim((string)$s->configs->get('api_base'), '/'), $s->configs->get('local_root')]));
    }
    public function policy(Strategy $s): ?R2ArchivePolicy
    {
        if (!Schema::hasTable('r2_archive_policies')) return null;
        return R2ArchivePolicy::where('source_identity', self::identity($s))->where('enabled', true)->first();
    }
    protected function gateway(Strategy $s): R2UploadGateway { return new R2UploadGateway($s); }
    private function root(): string
    {
        $root = storage_path('app/r2-quota');
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) throw new RuntimeException('无法创建 R2 容量预留目录');
        return $root;
    }
    /** Readers do not take the quota lock: publish complete leases with an atomic rename. */
    private function writeLease(string $path, array $lease): void
    {
        $tmp = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            $json = json_encode($lease, JSON_THROW_ON_ERROR);
            if (file_put_contents($tmp, $json) !== strlen($json) || !rename($tmp, $path)) {
                throw new RuntimeException('无法保存 R2 容量预留');
            }
        } finally { if (is_file($tmp)) @unlink($tmp); }
    }
    public function pending(string $identity): int
    {
        $bytes = 0;
        foreach (glob($this->root().'/'.$identity.'-*.json') ?: [] as $path) {
            $lease = json_decode((string)file_get_contents($path), true);
            if (($lease['expires'] ?? 0) > time()) $bytes += (int)$lease['bytes'];
        }
        return $bytes;
    }
    public function reserve(Strategy $s, int $bytes, ?string $token = null, ?string $key = null): ?string
    {
        $policy = $this->policy($s);
        if (!$policy) return null;
        if (!$policy->links_ready) throw new UploadException('R2 归档转发尚未配置完成');
        $identity = self::identity($s);
        $token ??= bin2hex(random_bytes(16));
        if (!preg_match('/^[a-f0-9]{32}$/D', $token)) throw new RuntimeException('无效的 R2 容量预留编号');
        $lock = fopen($this->root().'/'.$identity.'.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('无法锁定 R2 容量');
        try {
            $path = $this->root().'/'.$identity.'-'.$token.'.json';
            if (is_file($path)) {
                $existing=json_decode((string)file_get_contents($path), true);
                if (($existing['expires'] ?? 0) > time() && (int)($existing['bytes'] ?? -1) === $bytes) {
                    $existing['expires']=time()+86400;
                    $this->writeLease($path, $existing);
                    return $identity.'-'.$token;
                }
                @unlink($path);
            }
            $used = $this->gateway($s)->bucketBytes();
            if ($used + $this->pending($identity) + $bytes > $policy->trigger_bytes) {
                $policy->update(['requested'=>true]);
                throw new UploadException('R2 已接近 9.5GB，已请求后台迁移，请稍后重试上传');
            }
            $this->writeLease($path, ['bytes'=>$bytes, 'key'=>$key, 'expires'=>time()+86400]);
            return $identity.'-'.$token;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public function release(?string $lease): void
    {
        if ($lease && preg_match('/^[a-f0-9]{64}-[a-f0-9]{32}$/D', $lease)) @unlink($this->root().'/'.$lease.'.json');
    }
    public function pendingKeys(string $identity): array
    {
        $keys=[];
        foreach (glob($this->root().'/'.$identity.'-*.json') ?: [] as $path) {
            $lease=json_decode((string)file_get_contents($path), true);
            if (($lease['expires'] ?? 0)>time() && !empty($lease['key'])) $keys[$lease['key']]=true;
        }
        return $keys;
    }
    /** Relay and direct initializers use the same naming lock, but release it before transferring bytes. */
    public function reserveNamed(Strategy $s, string $preferred, int $bytes): array
    {
        $dir=storage_path('app/r2-direct');
        if (!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('无法预留 R2 文件名');
        $lock=fopen($dir.'/.names.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('无法锁定 R2 文件名');
        try {
            $identity=self::identity($s); $keys=$this->pendingKeys($identity);
            foreach (glob($dir.'/*/*/meta.json') ?: [] as $path) {
                $meta=json_decode((string)file_get_contents($path),true);
                $strategy=Strategy::find($meta['file']['strategy_id'] ?? 0);
                if ($strategy && self::identity($strategy)===$identity && ($meta['phase']??'')!=='aborted') $keys[$meta['key']]=true;
            }
            $ids=app(R2ArchiveService::class)->sourceIds($identity);
            $preferred=ltrim($preferred,'/'); $ext=pathinfo($preferred,PATHINFO_EXTENSION);
            $stem=substr($preferred,0,-strlen($ext)-1);
            for ($attempt=0;$attempt<100;$attempt++) {
                $key=$attempt===0?$preferred:$stem.'-'.bin2hex(random_bytes(3)).'.'.$ext;
                if (isset($keys[$key])) continue;
                if (\App\Models\R2ArchiveJob::where('source_identity',$identity)->where('source_path_hash',hash('sha256',$key))->exists()) continue;
                if (\App\Models\Image::whereIn('strategy_id',$ids)->whereIn('path',[dirname($key)==='.'?'':dirname($key),'/'.dirname($key)])
                    ->where('name',basename($key))->exists()) continue;
                if ($this->gateway($s)->find($key)) continue;
                return [$key,$this->reserve($s,$bytes,null,$key)];
            }
            throw new UploadException('无法分配未重名的 R2 文件名');
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
}
