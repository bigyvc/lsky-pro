<?php
$source=getenv('LSKY_TEST_APP_PATH') ?: __DIR__.'/../../src';
require $source.'/vendor/autoload.php';
$work=sys_get_temp_dir().'/lsky-tg-upload-'.bin2hex(random_bytes(8));mkdir($work,0700,true);
putenv('APP_ENV=testing');putenv('APP_KEY=base64:'.base64_encode(random_bytes(32)));
$app=require $source.'/bootstrap/app.php';$app->useStoragePath($work.'/storage');
foreach(['framework/cache/data','framework/sessions','framework/views','logs','app'] as $p)mkdir($work.'/storage/'.$p,0700,true);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array','session.driver'=>'array']);
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
Illuminate\Support\Facades\Cache::forever('configs',collect(config('convention.app')));
function check($value,$message){if(!$value)throw new Exception($message);echo "PASS $message\n";}
class TestTelegram extends App\Filesystem\TelegramAdapter {
 public int $sent=0;public array $deleted=[];public bool $badSize=false;public array $files=[];
 public function __construct(int $id,private string $rootDir){parent::__construct('TEST_TOKEN','-1001',$id,'http://telegram-bot-api:8081',120,$rootDir);mkdir($rootDir.'/123/documents',0700,true);}
 protected function callApi(string $method,array $options=[]):array{
  if($method==='sendDocument'){
   $this->sent++;$file=$this->rootDir.'/123/documents/file_'.$this->sent.'.png';
   $in=$options['multipart'][1]['contents'];$out=fopen($file,'wb');$size=stream_copy_to_stream($in,$out);fclose($out);
   $id='file-'.$this->sent;$this->files[$id]=$file;
   return ['message_id'=>$this->sent,'document'=>['file_id'=>$id,'file_size'=>$size+($this->badSize?1:0)]];
  }
  if($method==='getFile') return ['file_path'=>$this->files[$options['query']['file_id']]];
  if($method==='deleteMessage'){$this->deleted[]=$options['query']['message_id'];return [];}
  throw new Exception('Unexpected API method');
 }
 public function read(string $path):string {throw new Exception('copy must not load a whole image into a PHP string');}
}
class TestImageService extends App\Services\ImageService {
 public TestTelegram $adapter;
 public function getAdapter(App\Models\Strategy $s):League\Flysystem\FilesystemAdapter{return $this->adapter;}
 public function makeThumbnail(App\Models\Image $i,mixed $data,int $max=400,bool $force=false):void{}
}
$base=array_merge(config('convention.group'),[App\Enums\GroupConfigKey::MaximumFileSize=>524288,
 App\Enums\GroupConfigKey::ImageSaveQuality=>100,App\Enums\GroupConfigKey::IsEnableWatermark=>false,
 App\Enums\GroupConfigKey::IsEnableScan=>false,App\Enums\GroupConfigKey::PathNamingRule=>'0',App\Enums\GroupConfigKey::FileNamingRule=>'{filename}']);
$group=App\Models\Group::create(['name'=>'Guest','is_guest'=>true,'configs'=>$base]);
$strategy=App\Models\Strategy::create(['name'=>'TG','key'=>App\Enums\StrategyKey::Telegram,'configs'=>['bot_token'=>'TEST_TOKEN','chat_id'=>'-1001','api_base'=>'http://telegram-bot-api:8081','local_root'=>$work.'/tg']]);
$group->strategies()->attach($strategy->id);
$service=new TestImageService();$adapter=$service->adapter=new TestTelegram($strategy->id,$work.'/tg');
$app->instance(App\Services\ImageService::class,$service);
$session=$app->make('session')->driver();$session->start();
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLbtAAAAABJRU5ErkJggg==');
function upload(string $bytes,string $name='photo.png'):App\Models\Image{
 global $work,$service,$app,$strategy,$session;
 $path=$work.'/input-'.bin2hex(random_bytes(3));file_put_contents($path,$bytes);
 $file=new Illuminate\Http\UploadedFile($path,$name,null,null,true);
 $request=Illuminate\Http\Request::create('/upload','POST',['strategy_id'=>$strategy->id],[],['file'=>$file]);
 $request->setLaravelSession($session);$request->setUserResolver(fn()=>null);$app->instance('request',$request);
 return $service->store($request);
}
$image=upload($png);
check($image->path==='0' && $image->pathname==='0/photo.png','literal path rule zero persists exactly');
check(App\Models\TelegramFile::where('path',$image->pathname)->exists(),'image pathname and TG mapping are identical');
$handle=$image->filesystem()->readStream($image->pathname);check(stream_get_contents($handle)===$png,'actual image filesystem opens the uploaded guest image');fclose($handle);
$again=upload($png);check($again->pathname===$image->pathname && $adapter->sent===1,'valid duplicate reuses mapping without another remote upload');
App\Models\TelegramFile::where('path',$image->pathname)->delete();
$repaired=upload($png);check($repaired->pathname===$image->pathname && $adapter->sent===2,'upload repairs a missing TG mapping instead of copying a broken record');
$other=upload($png.'different');check(preg_match('/^0\/photo-[a-f0-9]{6}\.png$/D',$other->pathname)===1,'same original name with different content gets six hex digits');
$handle=$image->filesystem()->readStream($image->pathname);check(stream_get_contents($handle)===$png,'filename collision does not overwrite the first image');fclose($handle);
foreach([''=>'root.png','0//'=>'nested.png'] as $rule=>$name){$group->update(['configs'=>array_merge($base,[App\Enums\GroupConfigKey::PathNamingRule=>$rule])]);$row=upload($png.$name,$name);$expected=$rule===''?$name:'0/'.$name;check($row->pathname===$expected && App\Models\TelegramFile::where('path',$expected)->exists(),'root and repeated slashes use one canonical mapping path');}
$filesystem=new League\Flysystem\Filesystem($adapter);$filesystem->copy($image->pathname,'copy.png');
$handle=$filesystem->readStream('copy.png');check(stream_get_contents($handle)===$png,'TG copy streams the source without read() buffering');fclose($handle);
$sent=$adapter->sent;$filesystem->move('copy.png','copy.png');check($adapter->sent===$sent && $filesystem->fileExists('copy.png'),'moving a TG file to itself preserves its mapping');
$adapter->badSize=true;$stream=fopen('php://temp','w+b');fwrite($stream,'abc');rewind($stream);
try{$adapter->writeStream('bad-size.png',$stream,new League\Flysystem\Config());throw new Exception('expected mismatch');}
catch(League\Flysystem\UnableToWriteFile $e){check(!App\Models\TelegramFile::where('path','bad-size.png')->exists() && in_array($adapter->sent,$adapter->deleted),'mismatched upload cleans the new message and creates no mapping');}finally{fclose($stream);}$adapter->badSize=false;
App\Models\TelegramFile::saving(function($row){if($row->path==='failed-map.png')throw new RuntimeException('database unavailable');});
$stream=fopen('php://temp','w+b');fwrite($stream,'abc');rewind($stream);
try{$adapter->writeStream('failed-map.png',$stream,new League\Flysystem\Config());throw new Exception('expected mapping failure');}
catch(League\Flysystem\UnableToWriteFile $e){check(str_contains($e->getMessage(),'映射保存失败') && in_array($adapter->sent,$adapter->deleted),'mapping persistence failure is visible and cleans only the new remote message');}finally{fclose($stream);}
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($it as $item)$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());rmdir($work);
