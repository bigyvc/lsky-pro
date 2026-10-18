<?php
$source=getenv('LSKY_TEST_APP_PATH') ?: __DIR__.'/../../src';
require $source.'/vendor/autoload.php';
$work=sys_get_temp_dir().'/lsky-photo-import-'.bin2hex(random_bytes(8));mkdir($work,0700,true);
putenv('APP_ENV=testing');putenv('APP_KEY=base64:'.base64_encode(random_bytes(32)));
$app=require $source.'/bootstrap/app.php';$app->useStoragePath($work.'/storage');
foreach(['framework/cache/data','framework/sessions','framework/views','logs','app'] as $p)mkdir($work.'/storage/'.$p,0700,true);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array','session.driver'=>'array']);
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
Illuminate\Support\Facades\Cache::forever('configs',collect(config('convention.app')));
function check($v,$label){if(!$v)throw new Exception($label);echo "PASS $label\n";}
$original=base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAIBAQEBAQIBAQECAgICAgQDAgICAgUEBAMEBgUGBgYFBgYGBwkIBgcJBwYGCAsICQoKCgoKBggLDAsKDAkKCgr/2wBDAQICAgICAgUDAwUKBwYHCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgr/wAARCAADAAQDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD4rooor6A/oA//2Q==');$processed=base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAoHBwgHBgoICAgLCgoLDhgQDg0NDh0VFhEYIx8lJCIfIiEmKzcvJik0KSEiMEExNDk7Pj4+JS5ESUM8SDc9Pjv/2wBDAQoLCw4NDhwQEBw7KCIoOzs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozv/wAARCAADAAQDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDlaKKK2PaP/9k=');
class PhotoAdapter extends App\Filesystem\TelegramAdapter {
 public int $sends=0;public int $next=10;public array $deleted=[];public array $paths=[];public bool $unreadable=false;public bool $loseResponse=false;
 public function __construct(public string $root,public string $bytes,int $id){parent::__construct('TEST_TOKEN','-100001',$id,'http://telegram-bot-api:8081',120,$root);mkdir($root.'/123/photos',0700,true);}
 protected function callApi(string $method,array $options=[]):array {
  if($method==='sendMediaGroup'||$method==='sendPhoto') {
   $this->sends++;$messages=[];
   foreach($options['multipart'] as $p)if(preg_match('/^p[0-9]+$|^photo$/D',$p['name'])) {
    $id=++$this->next;$file=$this->root.'/123/photos/'.$id.'.jpg';file_put_contents($file,$this->bytes);$this->paths['f'.$id]=$file;
    $messages[]=['message_id'=>$id,'media_group_id'=>'group-'.$this->sends,'photo'=>[['file_id'=>'small','width'=>1,'height'=>1,'file_size'=>1],['file_id'=>'f'.$id,'width'=>4,'height'=>3,'file_size'=>strlen($this->bytes)]]];
    // Real multipart ownership may close caller resources after the request.
    $wrapper=new GuzzleHttp\Psr7\Stream($p['contents']);$wrapper->close();
   }
   if($this->loseResponse)throw new RuntimeException('lost response');
   return $method==='sendPhoto'?$messages[0]:$messages;
  }
  if($method==='getFile')return ['file_path'=>$this->paths[$options['query']['file_id']]];
  if($method==='deleteMessage'){$this->deleted[]=$options['query']['message_id'];return [];}
  throw new Exception('Unexpected method '.$method);
 }
 public function readStream(string $path){if($this->unreadable)throw new RuntimeException('download unavailable');return parent::readStream($path);}
}
class PhotoImages extends App\Services\ImageService {
 public PhotoAdapter $adapter;
 public function getAdapter(App\Models\Strategy $s):League\Flysystem\FilesystemAdapter{return $this->adapter;}
 public function makeThumbnail(App\Models\Image $i,mixed $data,int $max=400,bool $force=false):void{}
}
$group=App\Models\Group::create(['name'=>'Import','is_default'=>true,'configs'=>array_merge(config('convention.group'),[
 App\Enums\GroupConfigKey::MaximumFileSize=>524288,App\Enums\GroupConfigKey::LimitPerMinute=>100,
 App\Enums\GroupConfigKey::ImageSaveQuality=>100,App\Enums\GroupConfigKey::IsEnableWatermark=>false,App\Enums\GroupConfigKey::IsEnableScan=>false])]);
$strategy=App\Models\Strategy::create(['name'=>'TG','key'=>App\Enums\StrategyKey::Telegram,'configs'=>['bot_token'=>'TEST_TOKEN','chat_id'=>'-100001','api_base'=>'http://telegram-bot-api:8081','local_root'=>$work.'/tg']]);$group->strategies()->attach($strategy->id);
$user=App\Models\User::create(['name'=>'Import','email'=>'import@example.test','password'=>'test','status'=>App\Enums\UserStatus::Normal]);$user->forceFill(['capacity'=>1048576,'email_verified_at'=>now()])->save();
$album=$user->albums()->create(['name'=>'频道图片']);$other=App\Models\User::create(['name'=>'Other','email'=>'other@example.test','password'=>'test']);$foreign=$other->albums()->create(['name'=>'频道图片']);
Illuminate\Support\Facades\Auth::setUser($user);
$images=new PhotoImages();$adapter=$images->adapter=new PhotoAdapter($work.'/tg',$processed,$strategy->id);$app->instance(App\Services\ImageService::class,$images);
$import=new App\Services\TelegramPhotoImport();
function req($sourceGroup,$count=2,$albumId=null){global $user,$strategy,$album,$work,$original;
 $files=[];for($n=0;$n<$count;$n++){$path=$work.'/input-'.bin2hex(random_bytes(4)).'.jpg';file_put_contents($path,$original);$files[]=new Illuminate\Http\UploadedFile($path,'mzt-'.(100+$n).'.jpg',null,null,true);}
 $r=Illuminate\Http\Request::create('/api/v1/upload/telegram-photos','POST',['strategy_id'=>$strategy->id,'album_id'=>$albumId??$album->id,'source_group'=>$sourceGroup],[],['files'=>$files]);$r->setUserResolver(fn()=>$user);return $r;
}
$result=$import->store(req('123:g1'),$images);
check(count($result['images'])===2 && $result['media_group_id']==='group-1' && $adapter->sends===1,'one source post produces one TG photo group');
check(App\Models\Image::count()===2&&App\Models\TelegramFile::count()===2&&$album->fresh()->image_num===2,'each photo gets an owned album record and TG mapping');
foreach($result['images'] as $row){
 $image=App\Models\Image::where('key',$row['key'])->firstOrFail();$stream=$adapter->readStream($image->pathname);$bytes=stream_get_contents($stream);fclose($stream);
 check($bytes===$processed && $image->md5===md5($bytes) && $image->sha1===sha1($bytes) && abs($image->size-strlen($bytes)/1024)<0.0001,'records describe the actual processed TG JPEG bytes');
 check($row['source']['md5']===md5($original) && $row['album_id']===$album->id && str_starts_with($image->origin_name,'mzt-'),'source hash, filename and explicit album are retained');
}
$repeat=$import->store(req('123:g1'),$images);check($repeat===$result&&$adapter->sends===1&&App\Models\Image::count()===2,'same source post repeats from cache without new TG messages');
$import->store(req('123:s9',1),$images);check($adapter->sends===2&&App\Models\Image::count()===3,'single-photo source posts use sendPhoto');
try{$import->store(req('123:g2',2,$foreign->id),$images);throw new Exception('foreign album accepted');}catch(App\Exceptions\UploadException $e){check($adapter->sends===2,'foreign album rejected before sending');}
$oldConfig=$group->configs;
$group->update(['configs'=>$oldConfig->merge([App\Enums\GroupConfigKey::ImageSaveFormat=>'png'])]);$user->unsetRelation('group');
try{$import->store(req('123:g5'),$images);throw new Exception('conversion policy accepted');}catch(App\Exceptions\UploadException $e){check($adapter->sends===2,'format conversion cannot silently change photo mappings');}
$group->update(['configs'=>$oldConfig]);$user->unsetRelation('group');
$route=app('router')->getRoutes()->match(Illuminate\Http\Request::create('/api/v1/upload/telegram-photos','POST'));
check(in_array('auth:sanctum',$route->gatherMiddleware(),true),'photo group endpoint requires authenticated API access');
$before=App\Models\Image::count();$mappings=App\Models\TelegramFile::count();$adapter->unreadable=true;
try{$import->store(req('123:g3'),$images);throw new Exception('TG verification failure accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'download unavailable'),'read failure is visible');}
check(App\Models\Image::count()===$before&&App\Models\TelegramFile::count()===$mappings&&count($adapter->deleted)===2,'failed target verification rolls back records and removes only known new messages');
$attempts=$adapter->sends;$adapter->unreadable=false;
try{$import->store(req('123:g3'),$images);throw new Exception('failed group resent');}catch(App\Exceptions\UploadException $e){check($adapter->sends===$attempts,'failed group requires reconciliation instead of blind resending');}
$adapter->loseResponse=true;
try{$import->store(req('123:g4'),$images);throw new Exception('lost response accepted');}catch(RuntimeException $e){}
$attempts=$adapter->sends;
try{$import->store(req('123:g4'),$images);throw new Exception('uncertain group resent');}catch(App\Exceptions\UploadException $e){check($adapter->sends===$attempts&&App\Models\Image::count()===$before,'lost TG response cannot create false success or automatic duplicates');}
// A deleted group must not return cached links to deleted records.
$adapter->loseResponse=false;
$oldKeys=array_column($result['images'],'key');
App\Models\Image::where('key',$oldKeys[0])->delete();
$attempts=$adapter->sends;
try{$import->store(req('123:g1'),$images);throw new Exception('partly deleted group resent');}catch(App\Exceptions\UploadException $e){check($adapter->sends===$attempts,'partly deleted group requires reconciliation');}
App\Models\Image::whereIn('key',$oldKeys)->delete();
$fresh=$import->store(req('123:g1'),$images);
check($adapter->sends===$attempts+1 && !array_intersect($oldKeys,array_column($fresh['images'],'key')),'fully deleted completed group can be imported with new records');
check(count(glob($work.'/storage/app/tg-photo-import/*.retired-*'))===1,'old completed ledger is retained for audit');
check($import->store(req('123:g1'),$images)===$fresh && $adapter->sends===$attempts+1,'new completed group remains idempotent');
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($iterator as $item){if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());}
rmdir($work);
check(App\Models\GalleryPost::count()===2,'completed imports automatically create one gallery post per source group');
check(App\Models\GalleryPost::where('source_group','123:g1')->firstOrFail()->images()->count()===2,'reimport refreshes gallery membership without duplicating the source post');
echo "PASS photo group import complete\n";
