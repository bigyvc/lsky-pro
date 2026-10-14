<?php

namespace App\Console\Commands;

use App\Services\ChunkUploadStore;
use Illuminate\Console\Command;

class PruneUploadChunks extends Command
{
    protected $signature = 'uploads:prune-chunks';
    protected $description = '清理超过24小时未更新的分片上传任务';

    public function handle(): int
    {
        (new ChunkUploadStore(storage_path('app/upload-chunks')))->prune();
        app(\App\Services\R2DirectUpload::class)->prune();
        $this->info('过期分片清理完成');
        return 0;
    }
}
