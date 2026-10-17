<?php
$source=getenv('LSKY_TEST_APP_PATH') ?: __DIR__.'/../../src';
require $source.'/vendor/autoload.php';
$work=sys_get_temp_dir().'/lsky-r2-test-'.bin2hex(random_bytes(8));mkdir($work,0700,true);
putenv('APP_ENV=testing');putenv('APP_KEY=base64:'.base64_encode(random_bytes(32)));
$app=require $source.'/bootstrap/app.php';$app->useStoragePath($work.'/storage');
foreach(['framework/cache/data','framework/sessions','framework/views','logs','app'] as $p)mkdir($work.'/storage/'.$p,0700,true);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array','session.driver'=>'array']);
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
Illuminate\Support\Facades\Cache::forever('configs',collect(config('convention.app')));
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
function check($condition,$message){if(!$condition)throw new Exception($message);echo "PASS $message\n";}
class FakeR2 extends App\Services\R2UploadGateway {
 public array $uploads=[],$objects=[];public int $starts=0,$completions=0,$deletes=0,$aborts=0,$headers=0;public bool $lostResponse=false;public ?string $headerBytes=null;public array $headerLimits=[];
 public ?string $videoFixture=null;
 public function videoMetadata(string $key,string $name):array{return app(App\Services\VideoMetadata::class)->inspect($this->videoFixture,$name);}
 public function __construct(){}
 public function start(string $key,string $mime,string $id):string {$upload='u'.++$this->starts;$this->uploads[$upload]=['key'=>$key,'mime'=>$mime,'id'=>$id,'parts'=>[]];return $upload;}
 public function sign(string $key,string $id,int $number,int $size,string $md5):array{return ['url'=>'https://r2.test/'.$id.'?part='.$number,'md5'=>base64_encode(hex2bin($md5)),'size'=>$size];}
 public function parts(string $key,string $id):array{return $this->uploads[$id]['parts'];}
 public function complete(string $key,string $id,array $parts):void{$this->completions++;$u=$this->uploads[$id];$this->objects[$key]=['ContentLength'=>array_sum(array_column($parts,'Size')),'Metadata'=>['lsky-direct-id'=>$u['id']]];unset($this->uploads[$id]);if($this->lostResponse){$this->lostResponse=false;throw new RuntimeException('lost remote response');}}
 public function head(string $key):array{if(!isset($this->objects[$key]))throw new RuntimeException('not found');return $this->objects[$key];}
 public function find(string $key):?array{return $this->objects[$key]??null;}
 public function header(string $key,int $limit=65536):string{$this->headers++;$this->headerLimits[]=$limit;if($this->headerBytes!==null)return substr($this->headerBytes,0,$limit);return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLbtAAAAABJRU5ErkJggg==');}
 public function abort(string $key,string $id):void{$this->aborts++;unset($this->uploads[$id]);}
 public function delete(string $key):void{$this->deletes++;unset($this->objects[$key]);}
 public function populate(array $parts):void{$id=array_key_last($this->uploads);$rows=[];foreach($parts as $i=>$p)$rows[]=['PartNumber'=>$i+1,'Size'=>$p['size'],'ETag'=>'"'.$p['md5'].'"'];$this->uploads[$id]['parts']=array_reverse($rows);}
}
class TestDirect extends App\Services\R2DirectUpload {
 public function __construct(App\Services\ImageService $images,public FakeR2 $remote){parent::__construct($images);}
 protected function gateway(App\Models\Strategy $strategy):App\Services\R2UploadGateway{return $this->remote;}
}
$group=App\Models\Group::create(['name'=>'Admin','configs'=>array_merge(config('convention.group'),[
 App\Enums\GroupConfigKey::PathNamingRule=>'{uid}',App\Enums\GroupConfigKey::FileNamingRule=>'{filename}',
 App\Enums\GroupConfigKey::LimitPerMinute=>100,App\Enums\GroupConfigKey::MaximumFileSize=>524288,App\Enums\GroupConfigKey::ImageSaveQuality=>100,
 App\Enums\GroupConfigKey::IsEnableWatermark=>false,App\Enums\GroupConfigKey::IsEnableScan=>false,
])]);
$user=new App\Models\User(['name'=>'Admin','email'=>'admin@example.test','password'=>'test','capacity'=>1073741824,'status'=>App\Enums\UserStatus::Normal,'configs'=>[]]);
$user->group_id=$group->id;$user->is_adminer=true;$user->email_verified_at=now();$user->save();$user->group_id=$group->id;$user->capacity=1073741824;$user->save();
$strategy=App\Models\Strategy::create(['name'=>'R2','key'=>App\Enums\StrategyKey::S3,'configs'=>[
 'endpoint'=>'https://'.str_repeat('a',32).'.r2.cloudflarestorage.com','bucket'=>'test','region'=>'auto','url'=>'https://r2.example.test',
 'access_key_id'=>'TEST_KEY','secret_access_key'=>'TEST_SECRET',
]]);$group->strategies()->attach($strategy->id);
$session=$app->make('session')->driver();$session->start();
function requestFor($params=[]){global $user,$app,$session;Illuminate\Support\Facades\Auth::setUser($user);
 $r=Illuminate\Http\Request::create('/upload/r2-direct','POST',$params,[],[],['HTTP_ACCEPT'=>'application/json']);$r->setLaravelSession($session);$r->setUserResolver(fn()=>$user);$app->instance('request',$r);return $r;
}
function body($r){return json_decode($r->getContent(),true);}
$size=354*1024*1024+634*1024;$parts=[];
for($i=0;$i<ceil($size/App\Services\R2DirectUpload::CHUNK_SIZE);$i++)$parts[]=['size'=>min(App\Services\R2DirectUpload::CHUNK_SIZE,$size-$i*App\Services\R2DirectUpload::CHUNK_SIZE),'md5'=>md5('part'.$i)];
requestFor();
$template=view('components.upload',['_group'=>$group,'_is_notice'=>''])->render();
check(!str_contains($template,'id="upload-mode"'),'administrator uses automatic direct transport without a mode selector');
$strategiesView=view('layouts.strategies',['_group'=>$group])->render();
check(str_contains($strategiesView,'data-r2-direct="1"')&&!str_contains($strategiesView,'TEST_SECRET'),'strategy advertises direct capability without credentials');
$metadata=['name'=>'large.png','size'=>$size,'md5'=>md5('full'),'sha1'=>sha1('full'),'strategy_id'=>$strategy->id,'parts_json'=>json_encode($parts)];
$images=$app->make(App\Services\ImageService::class);$remote=new FakeR2();$service=new TestDirect($images,$remote);$controller=$app->make(App\Http\Controllers\R2DirectUploadController::class);
$first=body($controller->begin(requestFor($metadata),$service));check($first['status'],'administrator starts R2 upload');$id=$first['data']['id'];
check(count((array)$first['data']['parts'])===8&&$first['data']['concurrency']===6,'eight signed 50MiB parts, concurrency six');
check(!str_contains(json_encode($first),'TEST_SECRET'),'secret not returned');
check($remote->uploads[array_key_last($remote->uploads)]['key']===$user->id.'/large.png','first upload preserves original filename');
$again=body($controller->begin(requestFor($metadata),$service));check($again['data']['id']===$id&&$remote->starts===1,'lost init response recovers existing task');
$collisionMeta=array_merge($metadata,['sha1'=>sha1('different file')]);
$pending=body($controller->begin(requestFor($collisionMeta),$service));
$pendingKey=$remote->uploads[array_key_last($remote->uploads)]['key'];
check($pending['status']&&preg_match('~/large-[a-f0-9]{6}\.png$~',$pendingKey),'in-flight name reservation adds exactly six characters');
$controller->abort(requestFor(),$service,$pending['data']['id']);
$bad=body($controller->begin(requestFor(array_merge($metadata,['parts_json'=>'[]'])),$service));check(!$bad['status'],'malformed manifest rejected before remote call');
$bad=body($controller->begin(requestFor(array_merge($metadata,['strategy_id'=>999])),$service));check(!$bad['status'],'unassigned strategy rejected');
$user->is_adminer=false;$bad=body($controller->begin(requestFor($metadata),$service));check(!$bad['status'],'non-admin cannot obtain signing capability');$user->is_adminer=true;
$incomplete=body($controller->complete(requestFor(),$service,$id));check(!$incomplete['status']&&$remote->completions===0,'incomplete multipart cannot commit');
$remote->populate($parts);
$u=array_key_last($remote->uploads);$remote->uploads[$u]['parts'][0]['Size']--;
$bad=body($controller->complete(requestFor(),$service,$id));check(!$bad['status']&&$remote->completions===0,'actual remote part length verified');
$remote->populate($parts);$remote->lostResponse=true;
$bad=body($controller->complete(requestFor(),$service,$id));check(!$bad['status']&&$remote->completions===1,'lost completion response simulated');
$done=body($controller->complete(requestFor(),$service,$id));check($done['status']&&$remote->completions===1,'remote completed object recovered without retransmission');
$image=App\Models\Image::first();check($image&&abs($image->size-$size/1024)<0.01&&$image->width===1,'verified object registered with original dimensions and size');
check(str_contains($image->thumb_url,'static/large-image.svg'),'large original is not downloaded automatically as a thumbnail');
$repeat=body($controller->complete(requestFor(),$service,$id));check($repeat===$done&&App\Models\Image::count()===1,'duplicate completion creates no extra record');
try {$images->registerDirect(requestFor(),$strategy,array_merge($metadata,['md5'=>md5('other')]),$image->pathname,[0=>1,1=>1,'mime'=>'image/png']);throw new Exception('mismatched record accepted');}
catch (App\Exceptions\UploadException $e) {check(App\Models\Image::count()===1,'existing path cannot silently reuse a record with mismatched hashes or length');}
$controller->abort(requestFor(),$service,$id);check($remote->deletes===0,'abort cannot delete committed image');
$collision=body($controller->begin(requestFor($collisionMeta),$service));
check($collision['status']&&preg_match('~/large-[a-f0-9]{6}\.png$~',$remote->uploads[array_key_last($remote->uploads)]['key']),'registered filename collision adds short suffix');
$controller->abort(requestFor(),$service,$collision['data']['id']);
$remote->objects[$user->id.'/external.png']=['ContentLength'=>123,'Metadata'=>[]];
$external=body($controller->begin(requestFor(array_merge($metadata,['name'=>'external.png'])),$service));
check($external['status']&&preg_match('~/external-[a-f0-9]{6}\.png$~',$remote->uploads[array_key_last($remote->uploads)]['key']),'remote object without database record is protected');
$controller->abort(requestFor(),$service,$external['data']['id']);unset($remote->objects[$user->id.'/external.png']);
$race=body($controller->begin(requestFor(array_merge($metadata,['name'=>'race.png'])),$service));$remote->populate($parts);
$remote->objects[$user->id.'/race.png']=['ContentLength'=>7,'Metadata'=>[]];
$blocked=body($controller->complete(requestFor(),$service,$race['data']['id']));
check(!$blocked['status']&&$remote->objects[$user->id.'/race.png']['ContentLength']===7,'external write after initialization is not overwritten');
$controller->abort(requestFor(),$service,$race['data']['id']);unset($remote->objects[$user->id.'/race.png']);
$other=clone $user;$other->id=999;$r=requestFor(['index'=>0]);$r->setUserResolver(fn()=>$other);$denied=body($controller->sign($r,$service,$id)); // validation requires index

check(!$denied['status'],"another administrator cannot sign someone else's task");
$jpg=body($controller->begin(requestFor(array_merge($metadata,['name'=>'fake.jpg'])),$service));check($jpg['status'],'new task for mismatched image test');$remote->populate($parts);
$bad=body($controller->complete(requestFor(),$service,$jpg['data']['id']));check(!$bad['status']&&$remote->deletes===1&&App\Models\Image::count()===1,'actual header mismatch rejected and temporary object removed');
// A real JPEG SOF follows two maximum-size APP segments, beyond the old 64KiB prefix.
$remote->headerBytes="\xff\xd8".str_repeat("\xff\xe1".pack('n',65535).str_repeat('x',65533),2)
 ."\xff\xc0".pack('n',17)."\x08".pack('nn',6000,4000)."\x03\x01\x11\x00\x02\x11\x00\x03\x11\x00\xff\xd9";
check(@getimagesizefromstring(substr($remote->headerBytes,0,65536))===false,'camera JPEG dimensions can occur beyond the 64KiB prefix');
$camera=body($controller->begin(requestFor(array_merge($metadata,['name'=>'DSC_CAMERA.JPG'])),$service));$remote->populate($parts);
$cameraResult=body($controller->complete(requestFor(),$service,$camera['data']['id']));
check($cameraResult['status']&&App\Models\Image::where('origin_name','DSC_CAMERA.JPG')->value('width')===4000,'camera JPEG with large APP metadata commits original dimensions');
check(max($remote->headerLimits)===4*1024*1024,'JPEG header fallback is bounded at 4MiB');
$remote->headerBytes=null;
$user->capacity=1;$bad=body($controller->begin(requestFor(array_merge($metadata,['name'=>'new.png'])),$service));check(!$bad['status'],'capacity including pending tasks enforced');$user->capacity=1073741824;
$updated=$group->configs;$updated->put(App\Enums\GroupConfigKey::IsEnableScan,true);$group->configs=$updated;$group->save();$user->unsetRelation('group');
$bad=body($controller->begin(requestFor(array_merge($metadata,['name'=>'scan.png'])),$service));check(!$bad['status'],'required scanning cannot be bypassed by direct upload');
$updated=$group->configs;$updated->put(App\Enums\GroupConfigKey::IsEnableScan,false);$group->configs=$updated;$group->save();$user->unsetRelation('group');
$abandoned=body($controller->begin(requestFor(array_merge($metadata,['name'=>'abandoned.png'])),$service));check($abandoned['status'],'abandoned upload initialized');
foreach(glob($work.'/storage/app/r2-direct/*/*/meta.json') as $path){$m=json_decode(file_get_contents($path),true);$m['updated_at']=time()-86401;file_put_contents($path,json_encode($m));}
$service->prune();check($remote->aborts>0&&count($remote->objects)===2,'expired multipart aborted while committed object retained');
class FailingHead extends App\Services\R2UploadGateway {
 public function __construct(public int $status){}
 public function head(string $key):array {
  throw new Aws\S3\Exception\S3Exception('head failure',new Aws\Command('HeadObject'),['response'=>new GuzzleHttp\Psr7\Response($this->status)]);
 }
}
check((new FailingHead(404))->find('missing.png')===null,'only remote 404 is treated as an available filename');
try {(new FailingHead(403))->find('unknown.png');throw new Exception('403 must fail closed');}
catch(Aws\S3\Exception\S3Exception $e){check($e->getStatusCode()===403,'remote permission error cannot reserve an unchecked filename');}
// The real SDK signer must bind both part checksum and length without contacting R2.
$signed=(new App\Services\R2UploadGateway($strategy))->sign('test.png','upload-id',1,50*1024*1024,md5('part'));
parse_str(parse_url($signed['url'],PHP_URL_QUERY),$query);
check(str_contains($query['X-Amz-SignedHeaders'],'content-md5'),'real presigned request binds Content-MD5 (remote part sizes separately verified)');
check(!str_contains($signed['url'],'TEST_SECRET'),'real signing does not expose secret key');
$minio=clone $strategy;$minio->key=App\Enums\StrategyKey::Minio;$cfg=$minio->configs;$cfg['access_key']='TEST_MINIO';$cfg['secret_key']='TEST_MINIO_SECRET';$minio->configs=$cfg;
$minioSigned=(new App\Services\R2UploadGateway($minio))->sign('test.png','upload-id',1,3,md5('abc'));
check(str_contains($minioSigned['url'],'TEST_MINIO')&&!str_contains($minioSigned['url'],'TEST_MINIO_SECRET'),'R2 configured through MinIO uses its credential field names');
for ($i=0;$i<5;$i++) {
 $tiny=['name'=>'tiny-'.$i.'.png','size'=>3,'md5'=>md5('abc'),'sha1'=>sha1('abc'),'strategy_id'=>$strategy->id,'parts_json'=>json_encode([['size'=>3,'md5'=>md5('abc')]])];
 $started=body($controller->begin(requestFor($tiny),$service));check($started['status'],'small direct uploads do not consume three-large-file slots');
}
if (getenv('LSKY_TEST_VIDEO')) {
 require __DIR__.'/video-fixtures.php';
 $fixtures=videoFixtures($work);
 $config=$group->configs->all();$config[App\Enums\GroupConfigKey::AcceptedFileSuffixes]=array_merge($config[App\Enums\GroupConfigKey::AcceptedFileSuffixes],App\Services\VideoMetadata::EXTENSIONS);$group->configs=collect($config);$group->save();$user->setRelation('group',$group);
 $priorConfig=$group->configs->all();
 $largeConfig=$priorConfig;$largeConfig[App\Enums\GroupConfigKey::MaximumFileSize]=1048576;
 $group->configs=collect($largeConfig);$group->save();$user->setRelation('group',$group);
 foreach(['mp4'=>512*1024*1024+1,'m3u8'=>1048577] as $ext=>$size) {
  $manifest=[];$remaining=$size;
  while($remaining>0){$n=min(50*1024*1024,$remaining);$manifest[]=['size'=>$n,'md5'=>md5('oversized')];$remaining-=$n;}
  $beforeStarts=$remote->starts;
  try {
   $result=body($controller->begin(requestFor(['name'=>'oversized.'.$ext,'size'=>$size,'md5'=>md5('oversized'),'sha1'=>sha1('oversized'),'strategy_id'=>$strategy->id,'parts_json'=>json_encode($manifest)]),$service));
  } catch (Illuminate\Validation\ValidationException $e) {
   check($remote->starts===$beforeStarts && isset($e->errors()['size']),'oversized video rejected by request validator '.$ext);
   continue;
  }
  check(!$result['status'] && $remote->starts===$beforeStarts && str_contains($result['message'],'不能超过'),'oversized video rejected before remote upload '.$ext);
 }
 $group->configs=collect($priorConfig);$group->save();$user->setRelation('group',$group);
 foreach($fixtures as $ext=>$fixture){
  $length=filesize($fixture);$manifest=[['size'=>$length,'md5'=>md5_file($fixture)]];
  $meta=['name'=>'direct.'.$ext,'size'=>$length,'md5'=>md5_file($fixture),'sha1'=>sha1_file($fixture),'strategy_id'=>$strategy->id,'parts_json'=>json_encode($manifest)];
  $remote->videoFixture=$fixture;
  $begin=body($controller->begin(requestFor($meta),$service));check($begin['status'],'R2 video session begins '.$ext);
  $remote->populate($manifest);
  $complete=body($controller->complete(requestFor(),$service,$begin['data']['id']));
  if(!$complete['status'])fwrite(STDERR,json_encode($complete,JSON_UNESCAPED_UNICODE).PHP_EOL);
  check($complete['status'] && $complete['data']['mimetype']===App\Services\VideoMetadata::mime($ext),'R2 video inspected and registered '.$ext);
 }
}
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($it as $item)$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());rmdir($work);
