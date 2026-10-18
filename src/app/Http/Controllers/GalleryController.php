<?php
namespace App\Http\Controllers;

use App\Models\GalleryPost;
use App\Models\Image;
use App\Services\GallerySettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class GalleryController extends Controller
{
    private function photos($query): void
    {
        $s=app(GallerySettings::class)->read();
        $query->where('images.user_id',$s['user_id'])->where('images.album_id',$s['album_id'])
            ->where('images.is_unhealthy',false)->whereIn('images.mimetype',['image/jpeg','image/png','image/webp','image/gif']);
    }

    private function posts(): Builder
    {
        $s=app(GallerySettings::class)->read();
        return GalleryPost::where('user_id',$s['user_id'])->where('album_id',$s['album_id'])
            ->whereHas('images',fn($q)=>$this->photos($q));
    }

    private function find(string $key): GalleryPost
    {
        $post=$this->posts()->where('public_key',$key)->with(['images'=>fn($q)=>$this->photos($q)])->firstOrFail();
        abort_if($post->images->isEmpty(),404);
        return $post;
    }

    private function imageData(GalleryPost $post, Image $image): array
    {
        $root='/gallery-media/'.$post->public_key.'/'.$image->key;
        return ['key'=>$image->key,'name'=>$image->origin_name,'width'=>$image->width,'height'=>$image->height,
            'thumbnail'=>$root.'/thumbnail','url'=>$root.'/original'];
    }

    private function postData(GalleryPost $post, bool $detail=false): array
    {
        $photos=$post->images;
        $result=['key'=>$post->public_key,'number'=>$post->source_order,'title'=>'片刻 '.str_pad((string)$post->source_order,5,'0',STR_PAD_LEFT),
            'count'=>$photos->count(),'href'=>'/sets/'.$post->public_key,'cover'=>$this->imageData($post,$photos->first())];
        if ($detail) $result['images']=$photos->map(fn($i)=>$this->imageData($post,$i))->values()->all();
        return $result;
    }

    public function index(Request $request, ?string $key=null)
    {
        $initial=$key ? $this->postData($this->find($key),true) : null;
        return response()->view('dayv-gallery.index',['site'=>app(GallerySettings::class)->read(),'initial'=>$initial])
            ->header('Cache-Control','no-store');
    }

    public function listing(Request $request)
    {
        $v=$request->validate(['order'=>'nullable|in:latest,earliest,random','page'=>'nullable|integer|min:1|max:10000',
            'snapshot'=>'nullable|integer|min:0','seed'=>'nullable|regex:/^[a-f0-9]{16}$/D']);
        $order=$v['order']??'latest';$page=(int)($v['page']??1);$seed=$v['seed']??bin2hex(random_bytes(8));
        $query=$this->posts();$maximum=(int)((clone $query)->max('id') ?: 0);
        $snapshot=min((int)($v['snapshot']??$maximum),$maximum);$query->where('id','<=',$snapshot);
        $total=(clone $query)->count();
        if ($order==='random') $query->orderByRaw('CASE WHEN public_key >= ? THEN 0 ELSE 1 END',[$seed])->orderBy('public_key');
        else { $direction=$order==='earliest'?'asc':'desc';$query->orderBy('source_order',$direction)->orderBy('id',$direction); }
        $posts=$query->with(['images'=>fn($q)=>$this->photos($q)])->offset(($page-1)*24)->limit(25)->get();
        $more=$posts->count()>24;
        $photoCount=\Illuminate\Support\Facades\DB::table('gallery_post_images as member')
            ->join('gallery_posts as post','post.id','=','member.post_id')->join('images','images.id','=','member.image_id')
            ->where('post.user_id',app(GallerySettings::class)->read()['user_id'])
            ->where('post.album_id',app(GallerySettings::class)->read()['album_id'])
            ->where('images.user_id',app(GallerySettings::class)->read()['user_id'])
            ->where('images.album_id',app(GallerySettings::class)->read()['album_id'])
            ->where('images.is_unhealthy',false)->whereIn('images.mimetype',['image/jpeg','image/png','image/webp','image/gif'])
            ->distinct()->count('images.id');
        return response()->json(['sets'=>$posts->take(24)->filter(fn($p)=>$p->images->isNotEmpty())->map(fn($p)=>$this->postData($p))->values(),
            'next_page'=>$more?$page+1:null,'snapshot'=>$snapshot,'seed'=>$seed,'total'=>$total,'photos'=>$photoCount])->header('Cache-Control','no-store');
    }

    public function detail(string $key)
    {
        return response()->json($this->postData($this->find($key),true))->header('Cache-Control','no-store');
    }

    public function media(Request $request, string $post, string $key, string $kind)
    {
        $group=$this->find($post);$image=$group->images->firstWhere('key',$key);abort_unless($image,404);
        if ($kind==='thumbnail') {
            $path=public_path($image->getThumbnailPathname());
            if (is_file($path)) return response()->file($path,['Content-Type'=>'image/png','Cache-Control'=>'public, max-age=3600']);
            return response()->file(public_path('dayv-gallery/placeholder.svg'),['Content-Type'=>'image/svg+xml','Cache-Control'=>'public, max-age=60']);
        }
        return app(\App\Services\ArchivedImageOutput::class)->response($request,$image);
    }
}
