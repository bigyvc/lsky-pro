<?php
namespace App\Models;

class R2ArchiveJob extends Model
{
    protected $guarded = [];
    protected $attributes = ['phase'=>'pending'];
    protected $casts = ['image_id'=>'integer', 'source_strategy_id'=>'integer', 'target_strategy_id'=>'integer', 'policy_id'=>'integer', 'bytes'=>'integer'];
}
