<?php
$source=getenv('LSKY_TEST_APP_PATH') ?: __DIR__.'/../../src';
require $source.'/vendor/autoload.php';
$work=sys_get_temp_dir().'/lsky-share-'.bin2hex(random_bytes(6));mkdir($work,0700,true);
putenv('APP_ENV=testing');putenv('APP_KEY=base64:'.base64_encode(random_bytes(32)));
$app=require $source.'/bootstrap/app.php';$app->useStoragePath($work.'/storage');
foreach(['framework/cache/data','framework/sessions','framework/views','logs','app','files'] as $p)mkdir($work.'/storage/'.$p,0700,true);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array','session.driver'=>'array','app.url'=>'https://share.test']);
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
Illuminate\Support\Facades\Cache::forever('configs',collect(config('convention.app')));
Illuminate\Support\Facades\View::share('errors',new Illuminate\Support\ViewErrorBag());
$session=$app->make('session')->driver();$session->start();
function check($v,$s){if(!$v)throw new Exception($s);echo "PASS $s\n";}
$owner=App\Models\User::forceCreate(['name'=>'owner','email'=>'owner@test.invalid','password'=>'x']);
$other=App\Models\User::forceCreate(['name'=>'other','email'=>'other@test.invalid','password'=>'x']);
$admin=App\Models\User::forceCreate(['name'=>'admin','email'=>'admin@test.invalid','password'=>'x','is_adminer'=>true]);
$strategy=App\Models\Strategy::create(['name'=>'test','key'=>App\Enums\StrategyKey::Local,'configs'=>['url'=>'https://r2.test','root'=>$work.'/storage/files']]);
$adapter=new League\Flysystem\Local\LocalFilesystemAdapter($work.'/storage/files');
$service=new class($adapter) extends App\Services\ImageService {
 public function __construct(private $adapter){}
 public function getAdapter(App\Models\Strategy $strategy): League\Flysystem\FilesystemAdapter{return $this->adapter;}
};
$app->instance(App\Services\ImageService::class,$service);
$bytes=str_repeat('test-video-bytes',20);file_put_contents($work.'/storage/files/clip.mp4',$bytes);
$image=App\Models\Image::forceCreate(['user_id'=>$owner->id,'strategy_id'=>$strategy->id,'path'=>'','name'=>'clip.mp4',
 'origin_name'=>'<img src=x onerror=alert(1)>.mp4','extension'=>'mp4','mimetype'=>'video/mp4','size'=>strlen($bytes)/1024,
 'md5'=>md5($bytes),'sha1'=>sha1($bytes),'permission'=>App\Enums\ImagePermission::Public]);

$before=Illuminate\Support\Facades\DB::table('images')->where('id',$image->id)->first();
Illuminate\Support\Facades\Schema::table('images',fn($table)=>$table->dropColumn('video_download_enabled'));
Illuminate\Support\Facades\DB::table('migrations')->where('migration','2026_10_17_000001_add_video_download_enabled_to_images')->delete();
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
$after=Illuminate\Support\Facades\DB::table('images')->where('id',$image->id)->first();
check(json_encode($before)===json_encode($after),'additive migration preserves existing media row and defaults download off');
$controller=new App\Http\Controllers\VideoShareController();

function req($user=null,$data=[],$method='GET',$headers=[]){
 global $image,$app,$session;
 $r=Illuminate\Http\Request::create('/v/'.$image->key,$method,$data,[],[],$headers);
 $route=new Illuminate\Routing\Route(['GET','POST'],'v/{key}',fn()=>null);$route->bind($r);
 $r->setRouteResolver(fn()=>$route);$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);$app->instance('request',$r);$r->setUserResolver(fn()=>$user);return $r;
}
function status($fn,$code,$message){try{$fn();throw new Exception('Expected HTTP '.$code);}catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){check($e->getStatusCode()===$code,$message);}}
$response=$controller->show(req());$html=$response->getContent();
check(!str_contains($html,'下载原文件') && str_contains($html,'controlslist="nodownload"'),'public page hides download by default');
check(!str_contains($html,'<img src=x') && str_contains($html,'&lt;img'),'filename is escaped');
check($response->headers->get('Cache-Control')==='no-store, private','share page cannot cache owner controls');
status(fn()=>$controller->download(req(),new App\Services\ArchivedImageOutput()),403,'disabled download endpoint is rejected');
status(fn()=>$controller->settings(req($other,['download_enabled'=>1],'POST')),403,'another user cannot enable downloads');
status(fn()=>$controller->settings(req(null,['download_enabled'=>1],'POST')),403,'guest cannot enable downloads');
$controller->settings(req($owner,['download_enabled'=>1,'permission'=>0,'user_id'=>$other->id],'POST'));
$image->refresh();check($image->video_download_enabled && $image->user_id===$owner->id && $image->permission===1,'owner toggle persists without mass assignment');
$html=$controller->show(req())->getContent();check(str_contains($html,'下载原文件') && !str_contains($html,'name="download_enabled"'),'visitor sees enabled download but no settings');
$response=$controller->download(req(null,[],'GET',['HTTP_RANGE'=>'bytes=2-9']),new App\Services\ArchivedImageOutput());
ob_start();$response->sendContent();$part=ob_get_clean();
check($response->getStatusCode()===206 && $part===substr($bytes,2,8),'download Range streams original bytes');
check(str_starts_with($response->headers->get('Content-Disposition'),'attachment;') && str_contains($response->headers->get('Cache-Control'),'no-store'),'download is attachment and policy response not cached');
$head=$controller->download(req(null,[],'HEAD'),new App\Services\ArchivedImageOutput());
ob_start();$head->sendContent();$headBytes=ob_get_clean();
check($headBytes==='' && (int)$head->headers->get('Content-Length')===strlen($bytes),'enabled download HEAD returns headers without body');
$controller->settings(req($owner,['download_enabled'=>0],'POST'));$image->refresh();
status(fn()=>$controller->download(req(null,[],'HEAD'),new App\Services\ArchivedImageOutput()),403,'turning switch off immediately blocks HEAD');
$image->permission=App\Enums\ImagePermission::Private;$image->save();
status(fn()=>$controller->show(req()),404,'private share hidden from anonymous');
status(fn()=>$controller->show(req($other)),404,'private share hidden from other user');
check(str_contains($controller->show(req($owner))->getContent(),'显示下载按钮'),'private owner can manage');
$image->user_id=null;$image->save();
$controller->settings(req($admin,['download_enabled'=>1],'POST'));check($image->fresh()->video_download_enabled,'admin can manage guest video');
$image->permission=App\Enums\ImagePermission::Public;$image->is_unhealthy=true;$image->save();
status(fn()=>$controller->show(req()),404,'unhealthy share hidden from public');
$routes=$app['router']->getRoutes();
$route=$routes->getByName('video.settings');check(in_array('auth',$route->middleware(),true),'settings route requires authentication');
$app['env']='production';
$csrf=new App\Http\Middleware\VerifyCsrfToken($app,$app['encrypter']);
try{$csrf->handle(req($admin,['download_enabled'=>1],'POST'),fn()=>response('unexpected'));throw new Exception('CSRF bypass');}
catch(Illuminate\Session\TokenMismatchException $e){check(true,'POST settings requires CSRF token');}
check($image->links->get('share_url')===route('video.share',['key'=>$image->key]),'upload links include share page');

$probe=new App\Services\VideoMetadata();
$valid="#EXTM3U\n#EXT-X-TARGETDURATION:4\n#EXT-X-KEY:METHOD=AES-128,URI=\"https://key.example.test/key?a=1,b=2\"\n#EXTINF:4,\nhttps://media.example.test/part.ts\n#EXT-X-ENDLIST\n";
check($probe->playlist($valid)['codec']==='hls','HLS validates text without resolving external hosts');
foreach([
    str_replace('https://media.example.test/part.ts','part.ts',$valid),
    str_replace('https://key.example.test/key?a=1,b=2','file:///etc/passwd',$valid),
    str_replace('https://media.example.test/part.ts','http://media.example.test/part.ts',$valid),
    str_replace('https://media.example.test/part.ts','https://127.0.0.1/part.ts',$valid),
    str_replace('https://media.example.test/part.ts','https://[::1]/part.ts',$valid),
    "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\n\n",
    str_repeat('x',1048577),
] as $bad) {
    try{$probe->playlist($bad);throw new Exception('unsafe HLS accepted');}
    catch(App\Exceptions\UploadException $e){check(true,'HLS rejects unsafe, relative, empty or oversized input');}
}
$rules=(new App\Http\Requests\Admin\GroupRequest())->rules();
$rule=$rules['configs.accepted_file_suffixes'];
foreach(App\Services\VideoMetadata::EXTENSIONS as $ext) {
    check(!validator(['configs'=>['accepted_file_suffixes'=>[$ext]]],['configs.accepted_file_suffixes'=>$rule])->fails(),'role accepts '.$ext);
}
check(validator(['configs'=>['accepted_file_suffixes'=>['svg']]],['configs.accepted_file_suffixes'=>$rule])->fails(),'role still rejects SVG');
$controller->settings(req($admin,['download_enabled'=>0,'public_share'=>1],'POST'));$image->refresh();
check($image->permission===App\Enums\ImagePermission::Public,'admin can explicitly publish guest share');
$controller->settings(req($admin,['download_enabled'=>0,'public_share'=>0],'POST'));$image->refresh();
check($image->permission===App\Enums\ImagePermission::Private,'explicit unpublish persists');
status(fn()=>$controller->show(req()),404,'unpublished page immediately unavailable');
$image->extension='avi';$image->is_unhealthy=false;$image->save();
$html=$controller->show(req($admin))->getContent();
check(!str_contains($html,'<video ') && str_contains($html,'暂不提供网页播放'),'unsupported container gets download hint instead of broken player');
$image->extension='m3u8';$image->save();
check(str_contains($controller->show(req($admin))->getContent(),'浏览器原生HLS'),'HLS share explains native playback and external dependencies');


foreach(App\Services\VideoMetadata::AUDIO_EXTENSIONS as $ext) {
 $image->extension=$ext;$image->mimetype=App\Services\VideoMetadata::mime($ext);$image->save();
 $html=$controller->show(req($admin))->getContent();
 $playable=!in_array($ext,['wma','ape','aiff','aif'],true);
 check(str_contains($html,'<audio ')===$playable && !str_contains($html,'<video '),'audio share player or download-only hint '.$ext);
 check(str_contains($image->thumb_url,'static/audio.svg') && str_contains($image->links['html'],'&lt;audio '),'audio placeholder and embed markup '.$ext);
}
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($it as $item)$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());rmdir($work);
