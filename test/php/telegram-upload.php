<?php
$source=getenv('LSKY_TEST_APP_PATH') ?: __DIR__.'/../../src';
require $source.'/vendor/autoload.php';
$work=sys_get_temp_dir().'/lsky-tg-upload-'.bin2hex(random_bytes(8));mkdir($work,0700,true);
putenv('APP_ENV=testing');putenv('APP_KEY=base64:'.base64_encode(random_bytes(32)));
$app=require $source.'/bootstrap/app.php';$app->useStoragePath($work.'/storage');
foreach(['framework/cache/data','framework/sessions','framework/views','logs','app'] as $p)mkdir($work.'/storage/'.$p,0700,true);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().PHP_EOL.(getenv("LSKY_TEST_TRACE")?$e->getTraceAsString():""));exit(1);});
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array','session.driver'=>'array']);
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
Illuminate\Support\Facades\Cache::forever('configs',collect(config('convention.app')));
function check($value,$message){if(!$value)throw new Exception($message);echo "PASS $message\n";}
class TestTelegram extends App\Filesystem\TelegramAdapter {
 public array $filenames=[];public int $sent=0;public array $deleted=[];public bool $badSize=false;public array $files=[];
 public function __construct(int $id,private string $rootDir){parent::__construct('TEST_TOKEN','-1001',$id,'http://telegram-bot-api:8081',120,$rootDir);mkdir($rootDir.'/123/documents',0700,true);}
 protected function callApi(string $method,array $options=[]):array{
  if($method==='sendDocument'){
   $this->filenames[]=$options['multipart'][1]['filename'];
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
 public function makeThumbnail(App\Models\Image $i,mixed $data,int $max=400,bool $force=false):void{if(App\Services\VideoMetadata::isMedia($i->extension))throw new Exception("video thumbnail decoding attempted");}
}
$base=array_merge(config('convention.group'),[App\Enums\GroupConfigKey::MaximumFileSize=>524288,
 App\Enums\GroupConfigKey::LimitPerMinute=>100,App\Enums\GroupConfigKey::ImageSaveQuality=>100,App\Enums\GroupConfigKey::IsEnableWatermark=>false,
 App\Enums\GroupConfigKey::IsEnableScan=>false,App\Enums\GroupConfigKey::PathNamingRule=>'0',App\Enums\GroupConfigKey::FileNamingRule=>'{filename}']);
$group=App\Models\Group::create(['name'=>'Guest','is_guest'=>true,'configs'=>$base]);
$strategy=App\Models\Strategy::create(['name'=>'TG','key'=>App\Enums\StrategyKey::Telegram,'configs'=>['bot_token'=>'TEST_TOKEN','chat_id'=>'-1001','api_base'=>'http://telegram-bot-api:8081','local_root'=>$work.'/tg']]);
$group->strategies()->attach($strategy->id);
$service=new TestImageService();$adapter=$service->adapter=new TestTelegram($strategy->id,$work.'/tg');
$app->instance(App\Services\ImageService::class,$service);
$session=$app->make('session')->driver();$session->start();
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLbtAAAAABJRU5ErkJggg==');
function upload(string $bytes,string $name='photo.png',?App\Models\User $user=null,array $extra=[]):App\Models\Image{
 global $work,$service,$app,$strategy,$session;
 $path=$work.'/input-'.bin2hex(random_bytes(3));file_put_contents($path,$bytes);
 $file=new Illuminate\Http\UploadedFile($path,$name,null,null,true);
 $request=Illuminate\Http\Request::create('/upload','POST',array_merge(['strategy_id'=>$strategy->id],$extra),[],['file'=>$file]);
 $request->setLaravelSession($session);$app->instance('request',$request);$request->setUserResolver(fn()=>$user);
 return $service->store($request);
}
$image=upload($png);
check($image->path==='0' && $image->pathname==='0/photo.png','literal path rule zero persists exactly');
check(App\Models\TelegramFile::where('path',$image->pathname)->exists(),'image pathname and TG mapping are identical');
$handle=$image->filesystem()->readStream($image->pathname);check(stream_get_contents($handle)===$png,'actual image filesystem opens the uploaded guest image');fclose($handle);

check(str_contains($image->url,'/'.$image->key.'/photo.png'),'TG URL includes original filename');
check(!$image->links->has('share_url'),'upload response only offers original links');
$display=clone $image;$display->origin_name='云朵 - 我的楼兰.png';$display->name='physical-hash.png';
check(rawurldecode(basename(parse_url($display->url,PHP_URL_PATH)))==='云朵 - 我的楼兰.png','Chinese and spaces kept in URL label independently of physical naming');
$display->origin_name='0.png';check(str_ends_with($display->url,'/0.png'),'literal zero filename is preserved');
$display->origin_name='云朵 - 我的楼兰.png';
$display->name='physical-hash-a1b2c3.png';
check(rawurldecode(basename(parse_url($display->url,PHP_URL_PATH)))==='云朵 - 我的楼兰.png','record key distinguishes collisions without adding a suffix to the label');
$display->origin_name='../danger?#%/test.png';
check(str_contains($display->url,'/'.$image->key.'/test.png'),'label cannot inject storage paths or change record key');
$controller=new App\Http\Controllers\Controller();
$unicodeLink=url($image->key.'/'.rawurlencode('云朵 - 我的楼兰').'.png');
foreach([$image->url,$unicodeLink,url($image->key.'.png'),url($image->key.'/old-decorative-name.png')] as $link) {
 $request=Illuminate\Http\Request::create($link,'GET',[],[],[],['HTTP_RANGE'=>'bytes=0-15']);
 $route=$app['router']->getRoutes()->match($request);$request->setRouteResolver(fn()=>$route);
 $response=$controller->output($request,$service);
 ob_start();$response->sendContent();$prefix=ob_get_clean();
 check($response->getStatusCode()===206 && $prefix===substr($png,0,16),'new, old and storage-rename-compatible URLs read the same TG mapping');
}
$request=Illuminate\Http\Request::create($display->url,'HEAD');
$route=$app['router']->getRoutes()->match($request);$request->setRouteResolver(fn()=>$route);
$headResponse=$controller->output($request,$service);ob_start();$headResponse->sendContent();$headContent=ob_get_clean();
check($headContent==='' && (int)$headResponse->headers->get('Content-Length')===strlen($png),'named URL HEAD returns size without a body');
check($route->parameter('key')===$image->key && $route->parameter('extension')==='png','named URL route accepts encoded label and resolves stable key');

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
if (getenv('LSKY_TEST_VIDEO')) {
 require __DIR__.'/video-fixtures.php';
 $fixtures=videoFixtures($work);
 $suffixRule=(new App\Http\Requests\Admin\GroupRequest())->rules()['configs.accepted_file_suffixes'];
 foreach(App\Services\VideoMetadata::EXTENSIONS as $extension) {
  check(!validator(['configs'=>['accepted_file_suffixes'=>[$extension]]],['configs.accepted_file_suffixes'=>$suffixRule])->fails(),'role permits media format '.$extension);
 }
 check(validator(['configs'=>['accepted_file_suffixes'=>['svg']]],['configs.accepted_file_suffixes'=>$suffixRule])->fails(),'SVG remains forbidden');

 $group->update(['configs'=>array_merge($base,[App\Enums\GroupConfigKey::AcceptedFileSuffixes=>array_merge($base[App\Enums\GroupConfigKey::AcceptedFileSuffixes],App\Services\VideoMetadata::EXTENSIONS)])]);
 foreach($fixtures as $ext=>$fixture) {
  $metadata=app(App\Services\VideoMetadata::class)->inspect($fixture,'clip.'.$ext);
  $w=($ext==='m3u8'||App\Services\VideoMetadata::isAudio($ext))?0:($ext==='3gp'?128:64);$h=($ext==='m3u8'||App\Services\VideoMetadata::isAudio($ext))?0:($ext==='3gp'?96:48);
  check($metadata[0]===$w && $metadata[1]===$h && !empty($metadata['codec']),'actual video metadata '.$ext);
  $bytes=file_get_contents($fixture);$row=upload($bytes,'clip.'.$ext);
  check($row->mimetype===App\Services\VideoMetadata::mime($ext) && $row->width===$w && $row->height===$h,'TG video uses canonical MIME and dimensions '.$ext);
  check(str_contains($row->thumb_url,App\Services\VideoMetadata::isAudio($ext)?'static/audio.svg':'static/video.svg') && !str_contains($row->links['markdown'],'!['),'video placeholder and download links '.$ext);
  $response=app(App\Services\ArchivedImageOutput::class)->response(Illuminate\Http\Request::create('/'.$row->key.'.'.$ext,'GET',[],[],[],['HTTP_RANGE'=>'bytes=0-15']),$row);
  ob_start();$response->sendContent();$chunk=ob_get_clean();check($response->getStatusCode()===206 && $chunk===substr($bytes,0,16),'TG video Range byte output '.$ext);
 }
 foreach(array_map(fn($ext)=>'fake.'.$ext,App\Services\VideoMetadata::EXTENSIONS) as $name) {
  try {upload($png,$name);throw new Exception('renamed image accepted as video');}
  catch(App\Exceptions\UploadException $e) {check(true,'renamed non-video rejected '.$name);}
 }
 $group->update(['configs'=>array_merge($base,[App\Enums\GroupConfigKey::AcceptedFileSuffixes=>array_merge($base[App\Enums\GroupConfigKey::AcceptedFileSuffixes],App\Services\VideoMetadata::EXTENSIONS),App\Enums\GroupConfigKey::IsEnableScan=>true])]);
 try {upload(file_get_contents($fixtures['mp4']),'scan.mp4');throw new Exception('image scan bypassed');}
 catch(App\Exceptions\UploadException $e) {check(str_contains($e->getMessage(),'审查'),'video cannot silently bypass required image scan');}
}
App\Models\TelegramFile::saving(function($row){if($row->path==='failed-map.png')throw new RuntimeException('database unavailable');});
$stream=fopen('php://temp','w+b');fwrite($stream,'abc');rewind($stream);
try{$adapter->writeStream('failed-map.png',$stream,new League\Flysystem\Config());throw new Exception('expected mapping failure');}
catch(League\Flysystem\UnableToWriteFile $e){check(str_contains($e->getMessage(),'映射保存失败') && in_array($adapter->sent,$adapter->deleted),'mapping persistence failure is visible and cleans only the new remote message');}finally{fclose($stream);}
foreach ([null,'zGBFCE-双笙（陈元汐） – 我的一个道姑朋友.flac',"bad/dir\\name\r\n.flac"] as $label) {
 $stream=fopen('php://temp','w+b');fwrite($stream,'abc');rewind($stream);
 $config=$label===null?[]:['telegram_filename'=>$label];
 $adapter->writeStream('channel-label.flac',$stream,new League\Flysystem\Config($config));
 if(is_resource($stream))fclose($stream);
 $expected=$label===null?'channel-label.flac':str_replace(['/',chr(92),"\r","\n"],'_',$label);
 check(end($adapter->filenames)===$expected,'TG multipart uses safe channel label '.json_encode($label));
 check(App\Models\TelegramFile::where('strategy_id',$strategy->id)->where('path','channel-label.flac')->exists(),'channel label never changes the TG mapping key');
}
// Explicit album selection is scoped to the authenticated user and does not alter defaults.
$group->update(['configs'=>$base,'is_default'=>true]);
$owner=App\Models\User::create(['name'=>'Import','email'=>'import@example.test','password'=>'test','status'=>App\Enums\UserStatus::Normal]);
$owner->forceFill(['group_id'=>$group->id,'capacity'=>1048576,'email_verified_at'=>now()])->save();
$other=App\Models\User::create(['name'=>'Other','email'=>'other@example.test','password'=>'test','status'=>App\Enums\UserStatus::Normal]);
$defaultAlbum=$owner->albums()->create(['name'=>'Default']);$targetAlbum=$owner->albums()->create(['name'=>'频道图片']);$foreignAlbum=$other->albums()->create(['name'=>'频道图片']);
$owner->configs=$owner->configs->merge([App\Enums\UserConfigKey::DefaultAlbum=>$defaultAlbum->id]);$owner->save();
$explicit=upload($png,'album.png',$owner,['album_id'=>(string)$targetAlbum->id]);
check($explicit->album_id===$targetAlbum->id && $targetAlbum->fresh()->image_num===1 && $defaultAlbum->fresh()->image_num===0,'explicit owned album overrides default and updates the correct album count');
$normal=upload($png,'default.png',$owner);
check($normal->album_id===$defaultAlbum->id && $owner->fresh()->configs->get(App\Enums\UserConfigKey::DefaultAlbum)===$defaultAlbum->id,'normal uploads retain unchanged default album');
$none=upload($png,'none.png',$owner,['album_id'=>'0']);check($none->album_id===null,'explicit zero selects no album');
$count=App\Models\Image::count();$sent=$adapter->sent;
foreach([$foreignAlbum->id,999999,-1,'',null,[]] as $invalid) {
 try {upload($png,'invalid.png',$owner,['album_id'=>$invalid]);throw new Exception('invalid album accepted');}
 catch(App\Exceptions\UploadException $e) {check(true,'invalid or foreign album rejected '.json_encode($invalid));}
}
try {upload($png,'guest-album.png',null,['album_id'=>$targetAlbum->id]);throw new Exception('guest chose an album');}
catch(App\Exceptions\UploadException $e) {check(true,'guest cannot choose an owned album');}
check(App\Models\Image::count()===$count && $adapter->sent===$sent,'rejected albums produce no file records or TG messages');
Illuminate\Support\Facades\Auth::setUser($owner);
$profile=(new App\Http\Controllers\Api\V1\UserController())->index();
check(json_decode($profile->getContent(),true)['data']['upload_album_id_supported']===true,'authenticated API profile advertises explicit album support');
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($it as $item)$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());rmdir($work);
