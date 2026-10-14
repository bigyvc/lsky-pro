<?php
namespace App\Console\Commands;

use App\Services\R2ArchiveService;
use Illuminate\Console\Command;

class ArchiveR2 extends Command
{
    protected $signature='r2:archive {--dry-run : 仅显示容量，不迁移或删除} {--limit=25 : 每轮最多处理对象数}';
    protected $description='R2 达到阈值后，将最旧的原图校验迁移到自建 Telegram Bot API';
    public function handle(): int
    {
        try {
            $result=app(R2ArchiveService::class)->run((bool)$this->option('dry-run'), max(1, min(100, (int)$this->option('limit'))));
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
            foreach ($result as $report) if (is_array($report) && ($report['failed'] ?? 0)) return 1;
            return 0;
        } catch (\Throwable $e) { $this->error('归档未执行完成；原文件未因此被删除。请检查策略、TG 服务与共享目录。'); return 1; }
    }
}
