<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class GalleryPost extends Model
{
    protected $guarded = [];
    protected $casts = ['user_id'=>'integer','album_id'=>'integer','strategy_id'=>'integer','source_order'=>'integer'];

    public function images(): BelongsToMany
    {
        return $this->belongsToMany(Image::class,'gallery_post_images','post_id','image_id')
            ->withPivot('position')->orderBy('gallery_post_images.position');
    }
}
