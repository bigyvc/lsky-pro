<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Telegram 存储的文件映射（path -> file_id）。
 *
 * Telegram 不是按路径寻址的存储，上传后拿到的 file_id / message_id
 * 需要持久化在这里，读取、删除时再反查。
 *
 * @property int $id
 * @property int $strategy_id
 * @property string $path
 * @property string $file_id
 * @property int $message_id
 * @property int $size
 * @property string $mime_type
 */
class TelegramFile extends Model
{
    protected $fillable = [
        'strategy_id',
        'path',
        'file_id',
        'message_id',
        'size',
        'mime_type',
    ];

    protected $casts = [
        'id' => 'integer',
        'strategy_id' => 'integer',
        'message_id' => 'integer',
        'size' => 'integer',
    ];

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class, 'strategy_id', 'id');
    }
}
