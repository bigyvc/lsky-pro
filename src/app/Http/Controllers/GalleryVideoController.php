<?php
namespace App\Http\Controllers;

use App\Services\GallerySettings;
use App\Services\GalleryVideos;
use Illuminate\Http\Request;

class GalleryVideoController extends Controller
{
    public function index(Request $request,GalleryVideos $videos,?string $key=null)
    {
        $initial=$key?$videos->data($videos->find($key)):null;
        return response()->view('dayv-gallery.videos',['site'=>app(GallerySettings::class)->read(),'initial'=>$initial])->header('Cache-Control','no-store');
    }
    public function listing(Request $request,GalleryVideos $videos)
    {
        $v=$request->validate(['page'=>'nullable|integer|min:1|max:10000','order'=>'nullable|in:latest,earliest','snapshot'=>'nullable|integer|min:0']);
        $q=$videos->query();$max=(int)((clone $q)->max('id')?:0);$snapshot=min((int)($v['snapshot']??$max),$max);
        $q->where('id','<=',$snapshot);$total=(clone $q)->count();$page=(int)($v['page']??1);
        $items=$q->orderBy('id',($v['order']??'latest')==='earliest'?'asc':'desc')->offset(($page-1)*24)->limit(25)->get();
        return response()->json(['videos'=>$items->take(24)->map(fn($i)=>$videos->data($i))->values(),'total'=>$total,
            'next_page'=>$items->count()>24?$page+1:null,'snapshot'=>$snapshot])->header('Cache-Control','no-store');
    }
    public function media(Request $request,GalleryVideos $videos,string $key,string $kind)
    {
        $video=$videos->find($key);
        if($kind==='poster') {
            $path=$videos->posterPath($video);
            return response()->file($path?:public_path('static/video.svg'),['Content-Type'=>$path?'image/jpeg':'image/svg+xml','Cache-Control'=>'public, max-age=300']);
        }
        return app(\App\Services\ArchivedImageOutput::class)->response($request,$video);
    }
}
