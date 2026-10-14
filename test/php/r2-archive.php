<?php
$source=getenv('LSKY_TEST_APP_PATH') ?: __DIR__.'/../../src';
require $source.'/vendor/autoload.php';
$work=sys_get_temp_dir().'/lsky-archive-test-'.bin2hex(random_bytes(8));mkdir($work,0700,true);
putenv('APP_ENV=testing');putenv('APP_KEY=base64:'.base64_encode(random_bytes(32)));
$app=require $source.'/bootstrap/app.php';$app->useStoragePath($work.'/storage');
foreach(['framework/cache/data','framework/sessions','framework/views','logs','app'] as $p)mkdir($work.'/storage/'.$p,0700,true);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','cache.default'=>'array','session.driver'=>'array']);
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
// Simulate an installed 13-migration database, then apply only the new additive migration.
Illuminate\Support\Facades\Schema::drop('r2_archive_jobs');Illuminate\Support\Facades\Schema::drop('r2_archive_policies');
Illuminate\Support\Facades\DB::table('migrations')->where('migration','2026_10_14_120000_create_r2_archive_tables')->delete();
Illuminate\Support\Facades\DB::table('groups')->insert(['name'=>'migration-sentinel','configs'=>'{}','is_guest'=>false]);
$sentinelBefore=json_encode(Illuminate\Support\Facades\DB::table('groups')->where('name','migration-sentinel')->first());
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
$sentinelAfter=json_encode(Illuminate\Support\Facades\DB::table('groups')->where('name','migration-sentinel')->first());
if ($sentinelBefore!==$sentinelAfter || Illuminate\Support\Facades\DB::table('migrations')->count()!==14) throw new Exception('additive upgrade changed existing data');
echo "PASS upgrade from 13 migrations adds archival tables without modifying existing rows\n";
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
function check($yes,$label){if(!$yes)throw new Exception($label);echo "PASS $label\n";}
mkdir($work.'/r2');mkdir($work.'/tg');
class ArchiveGateway extends App\Services\R2UploadGateway {
 public bool $failDelete=false;public int $deletes=0;public int $extra=0;
 public function __construct(public string $root){}
 public function head(string $key):array {if(!is_file($this->root.'/'.$key))throw new RuntimeException('missing');return ['ETag'=>'"'.md5_file($this->root.'/'.$key).'"','ContentLength'=>filesize($this->root.'/'.$key)];}
 public function find(string $key):?array{return is_file($this->root.'/'.$key)?$this->head($key):null;}
 public function delete(string $key):void{if($this->failDelete)throw new RuntimeException('lost delete response');$this->deletes++;unlink($this->root.'/'.$key);}
 public function readStream(string $key){return fopen($this->root.'/'.$key,'rb');}
 public function bucketBytes():int{$n=$this->extra;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root,FilesystemIterator::SKIP_DOTS)) as $f)if($f->isFile())$n+=$f->getSize();return $n;}
}
class ArchiveFilesystem extends League\Flysystem\Filesystem {
 public bool $unreadable=false;
 public function readStream(string $key){if($this->unreadable)throw new RuntimeException('TG getFile failure');return parent::readStream($key);}
}
class ArchiveService extends App\Services\R2ArchiveService {
 public function __construct(public ArchiveGateway $remote,public League\Flysystem\Filesystem $src,public ArchiveFilesystem $dst){}
 protected function gateway(App\Models\Strategy $s):App\Services\R2UploadGateway{return $this->remote;}
 protected function filesystem(App\Models\Strategy $s):League\Flysystem\Filesystem{return $s->key===App\Enums\StrategyKey::Telegram?$this->dst:$this->src;}
}
class ArchiveQuota extends App\Services\R2ArchiveQuota {
 public function __construct(public ArchiveGateway $remote){}
 protected function gateway(App\Models\Strategy $s):App\Services\R2UploadGateway{return $this->remote;}
}
$group=App\Models\Group::create(['name'=>'Admin','configs'=>array_merge(config('convention.group'),[App\Enums\GroupConfigKey::IsEnableOriginalProtection=>false])]);
$r2=App\Models\Strategy::create(['name'=>'R2','key'=>2,'configs'=>['endpoint'=>'https://'.str_repeat('a',32).'.r2.cloudflarestorage.com','bucket'=>'test','url'=>'https://r2.example.test']]);
$tg=App\Models\Strategy::create(['name'=>'Archive TG','key'=>11,'configs'=>['api_base'=>'http://telegram-bot-api:8081','local_root'=>$work.'/tg','bot_token'=>'TEST_TOKEN','chat_id'=>'-100001','url'=>'https://example.test']]);
$gateway=new ArchiveGateway($work.'/r2');
$src=new League\Flysystem\Filesystem(new League\Flysystem\Local\LocalFilesystemAdapter($work.'/r2'));
$dst=new ArchiveFilesystem(new League\Flysystem\Local\LocalFilesystemAdapter($work.'/tg'));
$service=new ArchiveService($gateway,$src,$dst);$quota=new ArchiveQuota($gateway);$app->instance(App\Services\R2ArchiveQuota::class,$quota);
$policy=App\Models\R2ArchivePolicy::create(['source_strategy_id'=>$r2->id,'target_strategy_id'=>$tg->id,'source_identity'=>App\Services\R2ArchiveQuota::identity($r2),'target_identity'=>App\Services\R2ArchiveQuota::targetIdentity($tg),'enabled'=>true,'links_ready'=>true,'trigger_bytes'=>150,'target_bytes'=>1]);
function imageAt($name,$data){global $src,$group,$r2;$src->write('1/'.$name,$data);return App\Models\Image::unguarded(fn()=>App\Models\Image::create(['strategy_id'=>$r2->id,'group_id'=>$group->id,'path'=>'1','name'=>$name,'origin_name'=>$name,'size'=>strlen($data)/1024,'md5'=>md5($data),'sha1'=>sha1($data),'extension'=>'png','mimetype'=>'image/png','width'=>1,'height'=>1,'permission'=>1,'uploaded_ip'=>'127.0.0.1']));}
$first=imageAt('first.png',str_repeat('a',100));
[$relayName,$relayLease]=$quota->reserveNamed($r2,'1/first.png',10);
[$relayName2,$relayLease2]=$quota->reserveNamed($r2,'1/first.png',10);
check(preg_match('~^1/first-[a-f0-9]{6}\.png$~',$relayName)&&$relayName!==$relayName2,'relay naming protects existing objects and concurrent name reservations');
$quota->release($relayLease);$quota->release($relayLease2);
$lease=$quota->reserve($r2,40);
check($lease!==null&&$quota->pending($policy->source_identity)===40,'in-flight bytes are reserved');
try{$quota->reserve($r2,20);throw new Exception('overshoot allowed');}catch(App\Exceptions\UploadException $e){check(App\Models\R2ArchivePolicy::first()->requested,'projected quota overflow requests archival without starting upload');}
$quota->release($lease);$quota->release($lease);check($quota->pending($policy->source_identity)===0,'reservation release is idempotent');
$before=$service->run(true);check($before[0]['used']===100&&App\Models\R2ArchiveJob::count()===0&&$gateway->deletes===0,'dry run cannot copy, switch or delete');
$dst->unreadable=true;$r=$service->run();$job=App\Models\R2ArchiveJob::first();
check($r[0]['failed']===1&&$src->fileExists('1/first.png')&&$first->fresh()->strategy_id===$r2->id,'TG read failure leaves source and database untouched');
$dst->unreadable=false;$gateway->failDelete=true;$r=$service->run();$job->refresh();
check($job->phase==='switched'&&$first->fresh()->strategy_id===$tg->id&&$src->fileExists('1/first.png'),'delete failure keeps source after verified database switch');
$dst->unreadable=true;$r=$service->run();check($src->fileExists('1/first.png'),'resume never deletes source when TG verification fails');
$dst->unreadable=false;$gateway->failDelete=false;$r=$service->run();$job->refresh();
check($job->phase==='done'&&!$src->fileExists('1/first.png')&&$gateway->deletes===1,'verified resumable transfer deletes source exactly once');
$service->run();check($gateway->deletes===1&&App\Models\Image::count()===1,'repeated archival does not duplicate image records or deletes');
[$archivedName,$archiveLease]=$quota->reserveNamed($r2,'1/first.png',10);check($archivedName!=='1/first.png','archived old URL is never reused for new relay bytes');$quota->release($archiveLease);
check(str_contains($first->fresh()->url,$first->key.'.png')&&!str_contains($first->fresh()->url,'TEST_TOKEN'),'TG image link uses application output without changing group protection');
$bad=imageAt('corrupt.png',str_repeat('b',180));$bad->forceFill(['sha1'=>sha1('wrong')])->save();
$service->run();check($src->fileExists('1/corrupt.png')&&$bad->fresh()->strategy_id===$r2->id,'source hash mismatch is never migrated or deleted');
// Free this synthetic failed fixture to test a full 350MiB file under PHP's 64MiB limit.
$failed=App\Models\R2ArchiveJob::where('image_id',$bad->id)->first();$failed->update(['phase'=>'cancelled']);$src->delete('1/corrupt.png');App\Models\Image::where('id',$bad->id)->delete();
$bigPath=$work.'/r2/1/big.png';$out=fopen($bigPath,'wb');$chunk=str_repeat('z',1024*1024);for($i=0;$i<350;$i++)fwrite($out,$chunk);fclose($out);unset($chunk);
$bytes=filesize($bigPath);
$big=App\Models\Image::unguarded(fn()=>App\Models\Image::create(['strategy_id'=>$r2->id,'group_id'=>$group->id,'path'=>'1','name'=>'big.png','origin_name'=>'big.png','size'=>$bytes/1024,'md5'=>md5_file($bigPath),'sha1'=>sha1_file($bigPath),'extension'=>'png','mimetype'=>'image/png','width'=>1,'height'=>1,'permission'=>1,'uploaded_ip'=>'127.0.0.1']));
$service->run();$bigJob=App\Models\R2ArchiveJob::where('image_id',$big->id)->first();
check($bigJob?->phase==='done'&&$dst->fileSize($bigJob->target_path)===$bytes&&abs($big->fresh()->size-$bytes/1024)<0.01,'350MiB original migrated with unchanged bytes under 64MiB PHP memory');
$policy->refresh()->update(['trigger_bytes'=>250,'target_bytes'=>0,'requested'=>false]);
$cycleA=imageAt('cycle-a.png',str_repeat('c',100));$cycleB=imageAt('cycle-b.png',str_repeat('d',100));$cycleC=imageAt('cycle-c.png',str_repeat('e',100));
$gateway->extra=17;
$service->run(true,1);check(!$policy->fresh()->requested,'dry run does not start a drain cycle');
$service->run(false,1);check($policy->fresh()->requested&&$gateway->bucketBytes()===217,'threshold starts a persistent drain across batch limits');
$service->run(false,1);check($policy->fresh()->requested&&$gateway->bucketBytes()===117,'drain continues below the trigger on the next run');
$service->run(false,1);check(!$policy->fresh()->requested&&$gateway->bucketBytes()===17,'drain completes after all managed images migrate and preserves unrelated objects');
$afterCycle=$gateway->deletes;$new=imageAt('next-cycle.png','new');$service->run(false,1);
check($gateway->deletes===$afterCycle&&$new->fresh()->strategy_id===$r2->id,'new images stay in R2 until the next threshold');
$policy->update(['enabled'=>false]);$gateway->extra=0;
class OutputImage extends App\Models\Image {public static League\Flysystem\Filesystem $disk;public function filesystem():League\Flysystem\Filesystem{return self::$disk;}}
OutputImage::$disk=$dst;$outputImage=new OutputImage();$outputImage->forceFill($first->fresh()->getAttributes());
$request=Illuminate\Http\Request::create('/original','GET',[],[],[],['HTTP_RANGE'=>'bytes=3-8']);
$response=(new App\Services\ArchivedImageOutput())->response($request,$outputImage);ob_start();$response->sendContent();$content=ob_get_clean();
check($response->getStatusCode()===206&&$content==='aaaaaa'&&$response->headers->get('Content-Range')==='bytes 3-8/100','TG byte ranges stream exact bytes');
class TestOutput extends App\Services\ArchivedImageOutput {public function response(Illuminate\Http\Request $r,App\Models\Image $i):Symfony\Component\HttpFoundation\StreamedResponse{$mock=new OutputImage();$mock->forceFill($i->getAttributes());return parent::response($r,$mock);}}
$app->instance(App\Services\ArchivedImageOutput::class,new TestOutput());
$controller=new App\Http\Controllers\R2ArchiveController();$response=$controller->archived(Illuminate\Http\Request::create('/archive','GET',['path'=>'1/first.png']),$r2->id);
ob_start();$response->sendContent();$old=ob_get_clean();check($old===str_repeat('a',100),'old R2 path resolves to verified archived image');
class LocalTelegram extends App\Filesystem\TelegramAdapter {public string $filePath;protected function callApi(string $method,array $options=[]):array{return ['file_path'=>$this->filePath];}}
$adapter=new LocalTelegram('TEST_TOKEN','-100001',$tg->id,'http://telegram-bot-api:8081',1200,$work.'/tg');
App\Models\TelegramFile::create(['strategy_id'=>$tg->id,'path'=>'test.png','file_id'=>'ok-file','message_id'=>1,'size'=>100]);$adapter->filePath=$work.'/tg/'.$job->target_path;
$handle=$adapter->readStream('test.png');check(stream_get_contents($handle)===str_repeat('a',100),'local Bot API absolute path opens through shared read-only directory');fclose($handle);
App\Models\TelegramFile::create(['strategy_id'=>$tg->id,'path'=>'bad.png','file_id'=>'outside-file','message_id'=>2,'size'=>1]);$adapter->filePath=__FILE__;
try{$adapter->readStream('bad.png');throw new Exception('directory escape allowed');}catch(League\Flysystem\UnableToReadFile $e){check(!str_contains($e->getMessage(),'TEST_TOKEN'),'local API cannot read paths outside configured root');}
check(memory_get_peak_usage(true)<64*1024*1024,'migration and streaming stay within configured memory limit');
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $item)$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());rmdir($work);
