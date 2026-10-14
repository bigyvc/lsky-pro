<?php
namespace App\Console\Commands;

use App\Enums\StrategyKey;
use App\Models\R2ArchivePolicy;
use App\Models\Strategy;
use App\Services\R2ArchiveQuota;
use App\Services\R2ArchiveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ConfigureR2Archive extends Command
{
    protected $signature='r2:archive-config {--list} {--source=} {--target=} {--enable} {--disable} {--links-ready}
        {--trigger=9500000000} {--retain=0}';
    protected $description='列出策略或配置 R2 -> 自建 TG 归档（不输出任何密钥）';
    public function handle(): int
    {
        if ($this->option('list')) {
            $this->table(['ID','名称','类型'], Strategy::all()->map(fn($s)=>[$s->id,$s->name,Strategy::DRIVERS[$s->key] ?? $s->key])->all());
            $this->line(json_encode(R2ArchivePolicy::all()->toArray(), JSON_PRETTY_PRINT)); return 0;
        }
        try {
            $source=Strategy::findOrFail((int)$this->option('source'));
            if ($this->option('disable')) { R2ArchivePolicy::where('source_strategy_id',$source->id)->update(['enabled'=>false]); $this->info('自动归档已停用'); return 0; }
            $target=Strategy::findOrFail((int)$this->option('target'));
            $identity=R2ArchiveQuota::identity($source);
            $existing=R2ArchivePolicy::where('source_identity',$identity)->first();
            if ($existing && $existing->source_strategy_id !== $source->id) throw new RuntimeException('同一个 R2 桶已经配置归档');
            if (!ctype_digit((string)$this->option('trigger')) || !ctype_digit((string)$this->option('retain'))) throw new RuntimeException('容量必须是非负整数');
            $trigger=(int)$this->option('trigger'); $retain=(int)$this->option('retain');
            if ($retain < 0 || $trigger < 1 || $retain >= $trigger || $trigger > 9500000000) throw new RuntimeException('阈值必须不超过9.5GB，归档后目标容量必须小于阈值');
            $ready=(bool)($existing?->links_ready);
            if ($this->option('links-ready')) {
                $url=rtrim((string)$source->configs->get('url'), '/').'/.__lsky_archive_probe';
                $image=\App\Models\Image::whereIn('strategy_id', app(R2ArchiveService::class)->sourceIds($identity))->first();
                if (!$image) throw new RuntimeException('请先在 R2 上传一张小图，用于验证旧链接转发绑定');
                $head=(new \App\Services\R2UploadGateway($source))->head($image->pathname);
                $probe=Http::timeout(15)->get($url, ['path'=>$image->pathname, 'nonce'=>bin2hex(random_bytes(8))]);
                if (!$probe->successful() || $probe->json('lsky_r2_archive') !== 1 || (int)$probe->json('source') !== $source->id
                    || $probe->json('bucket_etag') !== ($head['ETag'] ?? null)
                    || (int)$probe->json('bucket_size') !== (int)$head['ContentLength']) {
                    throw new RuntimeException('旧链接 Worker 探测未通过，请先部署 Worker');
                }
                $ready=true;
            }
            $policy=new R2ArchivePolicy(['source_strategy_id'=>$source->id,'target_strategy_id'=>$target->id,
                'source_identity'=>$identity,'target_identity'=>R2ArchiveQuota::targetIdentity($target),
                'trigger_bytes'=>$trigger,'target_bytes'=>$retain,'enabled'=>(bool)$this->option('enable'),'links_ready'=>$ready]);
            app(R2ArchiveService::class)->validate($policy);
            if ($policy->enabled) {
                foreach (glob(storage_path('app/r2-direct/*/*/meta.json')) ?: [] as $path) {
                    $meta=json_decode((string)file_get_contents($path), true);
                    if (($meta['storage_identity'] ?? '') === $identity && !isset($meta['result'])
                        && ($meta['phase'] ?? '') !== 'aborted') throw new RuntimeException('请先完成或取消正在进行的 R2 直传任务');
                }
            }
            if ($existing && ($existing->target_identity !== $policy->target_identity || $existing->target_strategy_id !== $target->id)) throw new RuntimeException('已有归档策略不能换 Bot 或目标频道，请保留原目标');
            R2ArchivePolicy::updateOrCreate(['source_strategy_id'=>$source->id], $policy->getAttributes());
            $this->info('归档配置已保存；触发容量 '.$trigger.' 字节；'.($retain === 0 ? '每轮持续归档至全部图床图片迁出 R2。' : '归档至 '.$retain.' 字节。')); return 0;
        } catch (\Throwable $e) { $this->error($e instanceof RuntimeException ? $e->getMessage() : '配置失败，请检查策略 ID 和数据库迁移'); return 1; }
    }
}
