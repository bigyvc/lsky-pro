<?php
$source=getenv('LSKY_TEST_APP_PATH') ?: __DIR__.'/../../src';
require $source.'/vendor/autoload.php';
require_once $source.'/app/Exceptions/TelegramRateLimitException.php';
require_once $source.'/app/Filesystem/TelegramAdapter.php';
class RateAdapter extends App\Filesystem\TelegramAdapter {
 public array $waits=[];public int $calls=0;public array $bodies=[];
 public function __construct(array $responses) {
  parent::__construct('123456:PRIVATE','-100123',2,'http://private-api.example:8081');
  $handler=function($request,$options)use(&$responses){
   $this->calls++;$this->bodies[]=(string)$request->getBody();$request->getBody()->close();
   $next=array_shift($responses);if($next instanceof Throwable)return GuzzleHttp\Promise\Create::rejectionFor($next);
   return GuzzleHttp\Promise\Create::promiseFor($next);
  };
  $this->http=new GuzzleHttp\Client(['handler'=>$handler]);
 }
 protected function waitForPhotoRateLimit(int $seconds):void {$this->waits[]=$seconds;}
}
function ok($value,$label){if(!$value)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
function response($code,$data){return new GuzzleHttp\Psr7\Response($code,[],json_encode($data));}
function limited($seconds=18){return response(429,['ok'=>false,'error_code'=>429,'parameters'=>['retry_after'=>$seconds]]);}
function files(){ $out=[];foreach(['JPEG_ONE','JPEG_TWO'] as $name){$s=tmpfile();fwrite($s,$name);rewind($s);$out[]=['stream'=>$s,'filename'=>$name.'.jpg'];}return $out; }
$success=response(200,['ok'=>true,'result'=>[['message_id'=>1],['message_id'=>2]]]);
$a=new RateAdapter([limited(),$success]);$f=files();$r=$a->sendPhotoBatch($f);
ok(count($r)===2 && $a->calls===2 && $a->waits===[20],'explicit 429 waits retry_after plus margin then retries');
foreach($a->bodies as $body)ok(str_contains($body,'JPEG_ONE')&&str_contains($body,'JPEG_TWO'),'every HTTP attempt carries both complete files');
ok(is_resource($f[0]['stream'])&&is_resource($f[1]['stream']),'multipart disposal preserves original tmpfiles');
foreach([response(400,['ok'=>false,'error_code'=>400,'description'=>'IMAGE_PROCESS_FAILED']),response(500,['ok'=>false]),response(429,['error_code'=>429,'parameters'=>['retry_after'=>18]]),limited(120)] as $bad){
 $a=new RateAdapter([$bad]);try{$a->sendPhotoBatch(files());throw new LogicException('expected error');}catch(RuntimeException $e){ok($a->calls===1 && !$a->waits,'ambiguous or unsupported errors never retry');}
}
$a=new RateAdapter([limited(),limited(),limited()]);try{$a->sendPhotoBatch(files());throw new LogicException('expected limit');}catch(App\Exceptions\TelegramRateLimitException $e){ok($a->calls===3 && $a->waits===[20,20],'at most two extra attempts');}
$request=new GuzzleHttp\Psr7\Request('POST','http://private-api.example');
$a=new RateAdapter([new GuzzleHttp\Exception\ConnectException('secret',$request,null,['errno'=>28])]);try{$a->sendPhotoBatch(files());throw new LogicException('expected timeout');}catch(RuntimeException $e){ok($a->calls===1 && !$a->waits,'transport timeout is never retried');}
echo "PASS bounded Telegram rate limit recovery\n";
