<?php
$source=getenv('LSKY_TEST_APP_PATH') ?: __DIR__.'/../../src';
require $source.'/vendor/autoload.php';
$work=sys_get_temp_dir().'/lsky-gallery-'.bin2hex(random_bytes(8));mkdir($work,0700,true);
putenv('APP_ENV=testing');putenv('APP_KEY=base64:'.base64_encode(random_bytes(32)));
$app=require $source.'/bootstrap/app.php';$app->useStoragePath($work.'/storage');
foreach(['framework/cache/data','framework/sessions','framework/views','logs','app'] as $p)mkdir($work.'/storage/'.$p,0700,true);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
config(['app.url'=>'https://dayv.io','database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array','session.driver'=>'array']);
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
Illuminate\Support\Facades\Cache::forever('configs',collect(config('convention.app')));
function check($v,$label){if(!$v)throw new Exception($label);echo "PASS $label\n";}
$group=App\Models\Group::create(['name'=>'Gallery','is_default'=>true,'configs'=>config('convention.group')]);
$user=App\Models\User::create(['name'=>'Owner','email'=>'gallery@example.test','password'=>'test']);
$other=App\Models\User::create(['name'=>'Other','email'=>'other@example.test','password'=>'test']);
$album=$user->albums()->create(['name'=>'频道图片']);$private=$user->albums()->create(['name'=>'私有相册']);$foreign=$other->albums()->create(['name'=>'频道图片']);
$strategy=App\Models\Strategy::create(['name'=>'TG','key'=>App\Enums\StrategyKey::Telegram,'configs'=>['bot_token'=>'TEST_SECRET','chat_id'=>'-100001','api_base'=>'http://telegram-bot-api:8081']]);
$posts=app(App\Services\GalleryPosts::class);$settings=app(App\Services\GallerySettings::class);
function imageFor($owner,$album,$n,$unhealthy=false){global $strategy,$group;
 $image=new App\Models\Image();$image->forceFill(['user_id'=>$owner->id,'album_id'=>$album->id,'group_id'=>$group->id,'strategy_id'=>$strategy->id,
 'path'=>'tg-photo-import/test','name'=>'mzt-'.$n.'.jpg','origin_name'=>'mzt-'.$n.'.jpg','size'=>1,'mimetype'=>'image/jpeg','extension'=>'jpg',
 'md5'=>md5('image'.$n),'sha1'=>sha1('image'.$n),'width'=>600,'height'=>800,'permission'=>0,'is_unhealthy'=>$unhealthy]);$image->save();return $image;
}
$all=[];
for($n=1;$n<=28;$n++){$images=[imageFor($user,$album,$n*2-1),imageFor($user,$album,$n*2)];$all[]=$posts->record('123:g'.$n,$images);}
$hidden=$posts->record('123:g99',[imageFor($user,$private,99)]);
$someone=$posts->record('123:g100',[imageFor($other,$foreign,100)]);
$bad=$posts->record('123:g101',[imageFor($user,$album,101,true)]);
function http($path,$host='dayv.org',$method='GET') {global $app;
 $request=Illuminate\Http\Request::create('https://'.$host.$path,$method,server:['HTTP_ACCEPT'=>'application/json']);
 $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$response=$kernel->handle($request);$kernel->terminate($request,$response);return $response;
}
function data($path){$r=http($path);if($r->getStatusCode()!==200)throw new Exception('HTTP '.$r->getStatusCode().' '.substr($r->getContent(),0,1800));return json_decode($r->getContent(),true,512,JSON_THROW_ON_ERROR);}
check(http('/gallery-api/sets')->getStatusCode()===404,'gallery is disabled until explicit album publication');
$settings->write(['enabled'=>true,'host'=>'dayv.org','title'=>'DAYV · 影像之间','user_id'=>$user->id,'album_id'=>$album->id]);
$first=data('/gallery-api/sets');
check(count(http('/gallery-api/sets')->headers->getCookies())===0,'public gallery reads do not create login sessions or cookies');
check(count($first['sets'])===24 && $first['next_page']===2 && $first['total']===28 && $first['photos']===56,'bounded listing exposes only chosen owner album and healthy images');
check($first['sets'][0]['number']===55,'latest sorting follows source post order');
$earliest=data('/gallery-api/sets?order=earliest');check($earliest['sets'][0]['number']===1,'earliest sorting follows original channel order');
$json=json_encode($first);
foreach(['TEST_SECRET','file_id','strategy_id','uploaded_ip','registered_ip','user_id','source_group','api_base'] as $secret)check(!str_contains($json,$secret),'public JSON omits '.$secret);
$maximum=$first['snapshot'];$new=$posts->record('123:g200',[imageFor($user,$album,200)]);
$second=data('/gallery-api/sets?page=2&snapshot='.$maximum);
check(count($second['sets'])===4 && !array_intersect(array_column($first['sets'],'key'),array_column($second['sets'],'key')),'snapshot pagination does not duplicate or insert new posts halfway');
$random=data('/gallery-api/sets?order=random&seed=0123456789abcdef');$again=data('/gallery-api/sets?order=random&seed=0123456789abcdef');
check(array_column($random['sets'],'key')===array_column($again['sets'],'key'),'random order is stable for the same seed');
check(http('/gallery-api/sets?order=bogus')->getStatusCode()===422 && http('/gallery-api/sets?page=0')->getStatusCode()===422,'invalid pagination and ordering rejected');
$key=$all[0]->public_key;$detail=data('/gallery-api/sets/'.$key);
check(array_column($detail['images'],'name')===['mzt-1.jpg','mzt-2.jpg'],'set details retain source member order and independent links');
check(http('/gallery-api/sets/'.$hidden->public_key)->getStatusCode()===404 && http('/gallery-api/sets/'.$someone->public_key)->getStatusCode()===404,'private and foreign albums cannot be enumerated');
check(http('/gallery-api/sets','dayv.io')->getStatusCode()===404 && http('/login')->getStatusCode()===404 && http('/api/v1/strategies')->getStatusCode()===404,'gallery host cannot expose upload-site login or API routes');
$home=http('/');check($home->getStatusCode()===200 && str_contains($home->getContent(),'把片刻') && str_contains($home->getContent(),'Multiverse / HTML5 UP'),'homepage renders adapted theme and attribution');
check(http('/sets/'.$key)->getStatusCode()===200,'set deep links render directly');
check(str_contains($home->headers->get('Content-Security-Policy'),"script-src 'self'"),'gallery serves its own scripts without third-party runtime dependencies');
$thumb=http($detail['images'][0]['thumbnail']);check($thumb->getStatusCode()===200 && $thumb->headers->get('Content-Type')==='image/svg+xml','missing thumbnail returns a placeholder without downloading the original');
check(http('/gallery-media/'.$hidden->public_key.'/'.$detail['images'][0]['key'].'/original')->getStatusCode()===404,'media membership cannot be crossed between albums or sets');
class GalleryStream extends App\Services\ArchivedImageOutput {public int $calls=0;public function response(Illuminate\Http\Request $r,App\Models\Image $i):Symfony\Component\HttpFoundation\StreamedResponse {$this->calls++;return response()->stream(fn()=>null,200,['Content-Type'=>$i->mimetype]);}}
$stream=new GalleryStream();$app->instance(App\Services\ArchivedImageOutput::class,$stream);
check(http($detail['images'][0]['url'])->getStatusCode()===200 && $stream->calls===1,'full images use existing streaming service only after scope checks');
App\Models\Image::whereIn('key',array_column($detail['images'],'key'))->delete();
check(http('/gallery-api/sets/'.$key)->getStatusCode()===404 && http($detail['images'][0]['url'])->getStatusCode()===404,'deleted sets and originals immediately leave the public gallery');
$before=App\Models\GalleryPost::count();
$ledger=['phase'=>'done','source_group'=>'123:g2','result'=>['images'=>$all[1]->images->map(fn($i)=>['key'=>$i->key])->all()]];
check($posts->fromLedger($ledger,$user->id,$album->id) && App\Models\GalleryPost::count()===$before,'ledger backfill is idempotent');
check(!$posts->fromLedger($ledger,$other->id,$foreign->id) && !$posts->fromLedger(['phase'=>'sending'],$user->id,$album->id),'backfill cannot publish foreign or incomplete imports');
try{$settings->write(['enabled'=>true,'host'=>'dayv.io']);throw new Exception('main domain accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'独立域名'),'gallery config cannot replace the main upload domain');}
$a=imageFor($user,$album,300);$b=imageFor($user,$album,301);
$legacy=['phase'=>'done','source_group'=>'123:g300','result'=>['images'=>[['key'=>$a->key],['key'=>$b->key]]]];
mkdir($work.'/storage/app/tg-photo-import',0700,true);
file_put_contents($work.'/storage/app/tg-photo-import/'.str_repeat('a',64).'.json',json_encode($legacy));
Illuminate\Support\Facades\Artisan::call('gallery:sync');
check(App\Models\GalleryPost::where('source_group','123:g300')->firstOrFail()->images()->count()===2,'sync command indexes existing completed import groups without media downloads');
$code=Illuminate\Support\Facades\Artisan::call('gallery:configure',['--user'=>'gallery@example.test','--album'=>'频道图片','--host'=>'dayv.org','--enable'=>true]);
check($code===0 && $settings->read()['album_id']===$album->id,'configure command resolves the owned album without guessing IDs');

check(data('/gallery-api/videos')['total']===0,'videos default to no published album');
$videoAlbum=$user->albums()->create(['name'=>'频道视频']);
$ownVideos=[];
for($n=1;$n<=26;$n++) {
 $video=imageFor($user,$videoAlbum,1000+$n);$video->forceFill(['extension'=>'mp4','mimetype'=>'video/mp4','origin_name'=>'视频 '.$n.'.mp4'])->save();$ownVideos[]=$video;
}
$foreignVideo=imageFor($other,$foreign,5000);$foreignVideo->forceFill(['extension'=>'mp4','mimetype'=>'video/mp4'])->save();
$guest=imageFor($user,$videoAlbum,5001);$guest->forceFill(['user_id'=>null,'extension'=>'mp4','mimetype'=>'video/mp4'])->save();
$privateVideo=imageFor($user,$private,5002);$privateVideo->forceFill(['extension'=>'mp4','mimetype'=>'video/mp4'])->save();
$playlist=imageFor($user,$videoAlbum,5003);$playlist->forceFill(['extension'=>'m3u8','mimetype'=>'application/vnd.apple.mpegurl'])->save();
$unhealthyVideo=imageFor($user,$videoAlbum,5004,true);$unhealthyVideo->forceFill(['extension'=>'mp4','mimetype'=>'video/mp4'])->save();
$code=Illuminate\Support\Facades\Artisan::call('gallery:configure',['--user'=>$user->email,'--album'=>'频道图片','--video-album'=>'频道视频','--host'=>'dayv.org','--title'=>'芳华录','--enable'=>true]);
check($code===0 && $settings->read()['video_album_id']===$videoAlbum->id,'video publication requires an explicitly selected owned album');
$videos=data('/gallery-api/videos');
check($videos['total']===26 && count($videos['videos'])===24 && $videos['next_page']===2,'bounded video list omits foreign/private/guest/unhealthy videos and HLS playlists');
$vidJson=json_encode($videos);
foreach(['TEST_SECRET','file_id','strategy_id','user_id','api_base','source_group'] as $secret)check(!str_contains($vidJson,$secret),'video JSON omits '.$secret);
check(count(http('/gallery-api/videos')->headers->getCookies())===0,'public video endpoints do not create sessions');
$video=$ownVideos[0];$videoData=app(App\Services\GalleryVideos::class)->data($video);$calls=$stream->calls;
check(http('/videos')->getStatusCode()===200 && http('/videos/'.$video->key)->getStatusCode()===200,'video listing and deep-link playback page render');
$html=http('/videos/'.$video->key)->getContent();
check(str_contains($html,'preload="none"') && !preg_match('/<video[^>]*\ssrc=/', $html) && $stream->calls===$calls,'video pages have no original source until explicit play');
$poster=http($videoData['poster']);check($poster->getStatusCode()===200 && $poster->headers->get('Content-Type')==='image/svg+xml' && $stream->calls===$calls,'missing video poster never fetches original media');
foreach([$foreignVideo,$guest,$privateVideo,$playlist,$unhealthyVideo] as $hiddenVideo) {
 check(http('/videos/'.$hiddenVideo->key)->getStatusCode()===404 && http('/gallery-video-media/'.$hiddenVideo->key.'/original')->getStatusCode()===404,'video page and media enforce owner/album/type/health scope');
}
check(http('/gallery-api/videos?page=0')->getStatusCode()===422 && http('/gallery-api/videos?order=random')->getStatusCode()===422,'video pagination validates inputs');
$early=data('/gallery-api/videos?order=earliest');check($early['videos'][0]['key']===$video->key,'video earliest sort uses upload order');
$next=data('/gallery-api/videos?page=2&snapshot='.$videos['snapshot']);check(count($next['videos'])===2,'video pagination retains snapshot');
check(http($videoData['url'])->getStatusCode()===200 && $stream->calls===$calls+1,'explicit video original request uses existing streaming service');
$code=Illuminate\Support\Facades\Artisan::call('gallery:configure',['--user'=>$user->email,'--album'=>'频道图片','--enable'=>true]);
check($code===0 && $settings->read()['video_album_id']===$videoAlbum->id,'reconfiguring photos preserves existing video album');
$code=Illuminate\Support\Facades\Artisan::call('gallery:configure',['--user'=>$user->email,'--album'=>'频道图片','--video-album'=>'频道图片','--enable'=>true]);
check($code!==0 && $settings->read()['video_album_id']===$videoAlbum->id,'same album for photos and videos rejected without overwriting settings');
// Real one-frame poster generation with local Flysystem bytes, not live TG credentials.
$clip=$work.'/fixture.mp4';$process=new Symfony\Component\Process\Process(['ffmpeg','-y','-v','error','-f','lavfi','-i','color=c=green:s=128x96:d=1','-c:v','libx264','-pix_fmt','yuv420p',$clip]);$process->setTimeout(20);$process->mustRun();
$video->forceFill(['path'=>'','name'=>'fixture.mp4','size'=>filesize($clip)/1024,'width'=>128,'height'=>96])->save();
$property=new ReflectionProperty(App\Models\Image::class,'filesystem');$property->setAccessible(true);
$property->setValue($video,new League\Flysystem\Filesystem(new League\Flysystem\Local\LocalFilesystemAdapter($work)));
$service=app(App\Services\GalleryVideos::class);$service->prepare($video);
check(is_file($service->posterPath($video)) && $service->metadata($video)->duration>0,'operator preparation creates a real JPEG poster and duration from local video bytes');
check(http('/gallery-video-media/'.$video->key.'/poster')->headers->get('Content-Type')==='image/jpeg','prepared poster served only through scoped route');
$video->forceFill(['album_id'=>$private->id])->save();
check(http('/videos/'.$video->key)->getStatusCode()===404 && http('/gallery-video-media/'.$video->key.'/poster')->getStatusCode()===404,'moving video out of public album immediately removes poster and playback access');
$code=Illuminate\Support\Facades\Artisan::call('gallery:configure',['--user'=>$user->email,'--album'=>'频道图片','--disable-videos'=>true,'--enable'=>true]);
check($code===0 && data('/gallery-api/videos')['total']===0 && data('/gallery-api/sets')['total']>0,'disabling videos leaves photo gallery available');

$settings->write(['enabled'=>false,'host'=>'dayv.org']);check(http('/gallery-api/sets')->getStatusCode()===404,'disabling gallery immediately blocks its public endpoints');
$before=Illuminate\Support\Facades\DB::table('images')->orderBy('id')->get()->toJson();
foreach(['gallery_post_images','gallery_posts','gallery_site_settings'] as $table) Illuminate\Support\Facades\Schema::drop($table);
Illuminate\Support\Facades\DB::table('migrations')->where('migration','2026_10_18_120000_create_gallery_posts')->delete();
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
check(Illuminate\Support\Facades\DB::table('images')->orderBy('id')->get()->toJson()===$before && $settings->read()['enabled']===false,'existing image records survive additive gallery upgrade with publication disabled');
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($iterator as $item){if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($work);
echo "PASS DAYV gallery complete\n";
