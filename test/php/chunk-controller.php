<?php
require __DIR__.'/../../src/vendor/autoload.php';
$work=sys_get_temp_dir().'/lsky-controller-test-'.bin2hex(random_bytes(8));
mkdir($work,0700,true);
putenv('APP_ENV=testing');putenv('APP_KEY=base64:'.base64_encode(random_bytes(32)));
$app=require __DIR__.'/../../src/bootstrap/app.php';
$app->useStoragePath($work.'/storage');
foreach(['framework/cache/data','framework/sessions','framework/views','logs','app'] as $p) mkdir($work.'/storage/'.$p,0700,true);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array','session.driver'=>'array']);
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
Illuminate\Support\Facades\Cache::forever('configs',collect(config('convention.app')));
$group=App\Models\Group::create(['name'=>'Test guest','is_guest'=>true,'configs'=>array_merge(config('convention.group'),[
    App\Enums\GroupConfigKey::MaximumFileSize=>524288,
    App\Enums\GroupConfigKey::ImageSaveQuality=>100,
    App\Enums\GroupConfigKey::IsEnableWatermark=>false,
    App\Enums\GroupConfigKey::IsEnableScan=>false,
])]);
$strategy=App\Models\Strategy::create(['name'=>'Test local','key'=>App\Enums\StrategyKey::Local,'configs'=>['root'=>$work.'/output','url'=>'https://example.test/i']]);
$group->strategies()->attach($strategy->id);
$session=$app->make('session')->driver();$session->start();
function requestFor($path,$parameters=[],$files=[]){global $app,$session;
    $r=Illuminate\Http\Request::create($path,'POST',$parameters,[], $files, ['HTTP_ACCEPT'=>'application/json']);
    $r->setLaravelSession($session);$app->instance('request',$r);return $r;
}
function body($response){return json_decode($response->getContent(),true);}
function check($condition,$message){if(!$condition)throw new Exception($message);echo "PASS $message\n";}
$controller=$app->make(App\Http\Controllers\ChunkUploadController::class);
// A valid PNG followed by padding is enough to test >50MiB original-file passthrough.
$source=$work.'/large.png';$out=fopen($source,'wb');
fwrite($out,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLbtAAAAABJRU5ErkJggg=='));
for($i=0;$i<51;$i++)fwrite($out,str_repeat('x',1024*1024));fclose($out);
$size=filesize($source);$hash=hash_file('sha256',$source);
$meta=['name'=>'large.png','size'=>$size,'fingerprint'=>'test','strategy_id'=>$strategy->id];
$begin=body($controller->begin(requestFor('/upload/chunks',$meta)));check($begin['status'],'controller accepts permitted strategy');$id=$begin['data']['id'];
$in=fopen($source,'rb');
for($index=0;$index<2;$index++){
    $path=$work.'/part-'.$index;$out=fopen($path,'wb');stream_copy_to_stream($in,$out, min(App\Services\ChunkUploadStore::CHUNK_SIZE,$size-$index*App\Services\ChunkUploadStore::CHUNK_SIZE));fclose($out);
    $file=new Illuminate\Http\UploadedFile($path,'chunk.bin',null,null,true);
    $response=body($controller->part(requestFor('/upload/chunks/'.$id.'/part',['index'=>$index],['file'=>$file]),$id));check($response['status'],'controller accepts part '.$index);
}fclose($in);
$service=$app->make(App\Services\ImageService::class);
$result=body($controller->complete(requestFor('/upload/chunks/'.$id.'/complete'),$service,$id));
check($result['status'],'complete through real ImageService and Flysystem');
$image=App\Models\Image::first();check($image!==null&&App\Models\Image::count()===1,'one image record');
check(hash_file('sha256',$work.'/output/'.$image->pathname)===$hash,'stored original bytes match');
$result2=body($controller->complete(requestFor('/upload/chunks/'.$id.'/complete'),$service,$id));check($result2===$result&&App\Models\Image::count()===1,'duplicate completion creates no extra record');
$bad=body($controller->begin(requestFor('/upload/chunks',array_merge($meta,['strategy_id'=>9999]))));check(!$bad['status'],'unauthorized strategy rejected');
$group->update(['configs'=>array_merge(config('convention.group'),[App\Enums\GroupConfigKey::MaximumFileSize=>1])]);
$bad=body($controller->begin(requestFor('/upload/chunks',$meta)));check(!$bad['status'],'role file limit enforced');
$routes=$app->make('router')->getRoutes();
check($routes->match(Illuminate\Http\Request::create('/upload/chunks','POST'))->getActionMethod()==='begin','init route registered');
check($routes->match(Illuminate\Http\Request::create('/upload/chunks/'.$id.'/part','POST'))->getActionMethod()==='part','part route registered');
if(is_link(getcwd().'/i') && readlink(getcwd().'/i')===$work.'/output') unlink(getcwd().'/i');
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($it as $item)$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());rmdir($work);
