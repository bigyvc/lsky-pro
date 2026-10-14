<?php
namespace App\Models;

class R2ArchivePolicy extends Model
{
    protected $guarded = [];
    protected $casts = ['source_strategy_id'=>'integer', 'target_strategy_id'=>'integer', 'enabled'=>'boolean', 'links_ready'=>'boolean', 'requested'=>'boolean',
        'trigger_bytes'=>'integer', 'target_bytes'=>'integer'];
}
