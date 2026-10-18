<?php
namespace App\Services;

use App\Models\GalleryPost;
use App\Models\Image;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GalleryPosts
{
    /** Internal only: neither clients nor public visitors can choose image membership. */
    public function record(string $sourceGroup, array $images): GalleryPost
    {
        if (!$images || count($images)>10 || !preg_match('/^[0-9]+:[gs][0-9]+$/D',$sourceGroup)) throw new RuntimeException('照片组索引参数无效');
        $first=$images[0]; $positions=[]; $sourceIds=[];
        foreach ($images as $n=>$image) {
            if (!$image->user_id || !$image->album_id || $image->user_id!==$first->user_id || $image->album_id!==$first->album_id
                || $image->strategy_id!==$first->strategy_id || $image->mimetype!=='image/jpeg') throw new RuntimeException('照片组归属或类型不符');
            $hasId=preg_match('/^mzt-([0-9]+)\.jpe?g$/iD',$image->origin_name,$match);
            $positions[$image->id]=['position'=>$n]; $sourceIds[]=$hasId?(int)$match[1]:0;
        }
        if (count($positions)!==count($images)) throw new RuntimeException('照片组记录重复');
        return DB::transaction(function () use ($first,$sourceGroup,$sourceIds,$positions) {
            $identity=['user_id'=>$first->user_id,'album_id'=>$first->album_id,'strategy_id'=>$first->strategy_id,'source_group'=>$sourceGroup];
            $post=GalleryPost::firstOrCreate($identity,['public_key'=>bin2hex(random_bytes(16)),'source_order'=>min($sourceIds)]);
            $post->source_order=min($sourceIds);$post->save();$post->images()->sync($positions);
            return $post;
        });
    }

    public function fromLedger(array $ledger, int $userId, int $albumId): bool
    {
        if (($ledger['phase']??null)!=='done') return false;
        $keys=array_column($ledger['result']['images']??[],'key');
        if (!$keys || count($keys)>10 || count(array_unique($keys))!==count($keys)) return false;
        $found=Image::where('user_id',$userId)->where('album_id',$albumId)->whereIn('key',$keys)->get()->keyBy('key');
        if ($found->count()!==count($keys)) return false;
        $this->record($ledger['source_group']??'',array_map(fn($key)=>$found[$key],$keys));
        return true;
    }
}
