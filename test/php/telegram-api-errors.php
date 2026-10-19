<?php
$source=getenv('LSKY_TEST_APP_PATH') ?: __DIR__.'/../../src';
require $source.'/vendor/autoload.php';
require_once $source.'/app/Filesystem/TelegramAdapter.php';
class DiagnosticAdapter extends App\Filesystem\TelegramAdapter {
    public function __construct($response) {
        parent::__construct('123456789:DO_NOT_LEAK_BOT_TOKEN_SECRET','-100999888',2,'http://private-api.example:8081');
        $this->http=new GuzzleHttp\Client(['handler'=>GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler([$response]))]);
    }
    public function invoke(string $method='sendMediaGroup'): array {return $this->callApi($method);}
}
function check($v,$message){if(!$v)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
function failure($response):string {
    try {(new DiagnosticAdapter($response))->invoke();throw new LogicException('expected API error');}
    catch(RuntimeException $e){
        check($e->getPrevious()===null,'API exception does not chain credential-bearing transport errors');
        foreach(['DO_NOT_LEAK','private-api.example','secret.example'] as $secret)check(!str_contains($e->getMessage(),$secret),'diagnostic omits '.$secret);
        return $e->getMessage();
    }
}
$response=fn($status,$data)=>new GuzzleHttp\Psr7\Response($status,[],json_encode($data));
$message=failure($response(429,['ok'=>false,'error_code'=>429,'description'=>'Too Many Requests retry after 37 https://secret.example/DO_NOT_LEAK','parameters'=>['retry_after'=>37]]));
check(str_contains($message,'HTTP=429 TG=429') && str_contains($message,'retry_after=37s') && str_contains($message,'RATE_LIMIT'),'HTTP429 retains safe Telegram retry details');
$message=failure($response(400,['ok'=>false,'error_code'=>400,'description'=>'Bad Request: failed to send message #3 with error message PHOTO_INVALID_DIMENSIONS secret DO_NOT_LEAK']));
check(str_contains($message,'PHOTO_INVALID_DIMENSIONS:item3'),'bad media retains a fixed reason and item number only');
$message=failure($response(403,['ok'=>false,'error_code'=>403,'description'=>'Forbidden: bot was kicked from chat']));
check(str_contains($message,'CHAT_WRITE_FORBIDDEN'),'permission failure is distinguishable');
$message=failure(new GuzzleHttp\Psr7\Response(502,[],'<html>DO_NOT_LEAK https://secret.example</html>'));
check(str_contains($message,'HTTP=502') && str_contains($message,'HTTP_ERROR'),'non-JSON HTTP failure never leaks raw body');
$request=new GuzzleHttp\Psr7\Request('POST','http://private-api.example/bot123456789:DO_NOT_LEAK_BOT_TOKEN_SECRET/sendMediaGroup');
$message=failure(new GuzzleHttp\Exception\ConnectException('DO_NOT_LEAK https://secret.example',$request,null,['errno'=>28]));
check(str_contains($message,'curl=28 category=TIMEOUT'),'transport timeout retains only cURL errno');
$message=failure(new GuzzleHttp\Exception\RequestException('DO_NOT_LEAK',$request,$response(400,['ok'=>false,'error_code'=>400,'description'=>'Bad Request: IMAGE_PROCESS_FAILED'])));
check(str_contains($message,'HTTP=400 TG=400') && str_contains($message,'IMAGE_PROCESS_FAILED'),'response-bearing custom handler errors remain diagnosable');
check((new DiagnosticAdapter($response(200,['ok'=>true,'result'=>[['message_id'=>5]]])))->invoke()===[['message_id'=>5]],'successful photo group results remain unchanged');
check((new DiagnosticAdapter($response(200,['ok'=>true,'result'=>true])))->invoke('deleteMessage')===['ok'=>true],'boolean deleteMessage result does not violate array return type');
echo "PASS Telegram API diagnostics complete\n";
