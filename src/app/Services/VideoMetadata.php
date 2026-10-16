<?php
namespace App\Services;

use App\Exceptions\UploadException;
use Symfony\Component\Process\Process;

/** Metadata inspection only: never transcode, generate posters or trust a renamed extension. */
class VideoMetadata
{
    public const EXTENSIONS = ['mp4', 'mov', 'webm', 'hevc', 'h265'];
    public static function isVideo(string $extension): bool { return in_array(strtolower($extension), self::EXTENSIONS, true); }
    public static function mime(string $extension): string
    {
        return match (strtolower($extension)) {
            'mp4'=>'video/mp4', 'mov'=>'video/quicktime', 'webm'=>'video/webm',
            'hevc','h265'=>'video/h265', default=>throw new UploadException('不支持的视频类型'),
        };
    }
    private function slot()
    {
        $dir=storage_path('app/video-probes');
        if (!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) throw new UploadException('无法创建视频校验目录');
        $until=microtime(true)+15;
        do {
            for ($index=0;$index<2;$index++) {
                $lock=fopen($dir.'/'.$index.'.lock','c');
                if (!$lock) throw new UploadException('无法打开视频校验锁');
                if (flock($lock,LOCK_EX|LOCK_NB)) return $lock;
                fclose($lock);
            }
            usleep(50000);
        } while (microtime(true)<$until);
        throw new UploadException('视频校验繁忙，请稍后重试');
    }
    public function inspect(string $input, string $name, bool $remote = false): array
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = self::mime($extension);
        // Explicit demuxers prevent a renamed playlist from opening arbitrary protocols/URLs.
        $format = match ($extension) { 'mp4','mov'=>'mov', 'webm'=>'matroska', default=>'hevc' };
        if ($remote && parse_url($input, PHP_URL_SCHEME) !== 'https') throw new UploadException('视频元数据地址无效');
        if (!$remote && (!is_file($input) || filesize($input) > 512*1024*1024)) throw new UploadException('视频文件不存在或超过512MiB');
        $command = ['ffprobe','-v','error','-max_alloc','16777216','-protocol_whitelist',$remote?'https,tls,tcp':'file',
            '-threads','1','-rw_timeout','10000000','-probesize','1048576','-analyzeduration','1000000','-f',$format];
        if ($remote) array_push($command, '-tls_verify', '1', '-ca_file', '/etc/ssl/certs/ca-certificates.crt');
        if ($format === 'mov') array_push($command, '-enable_drefs', '0');
        array_push($command,'-i',$input,'-select_streams','v:0','-show_entries','stream=codec_name,width,height','-of','json');
        $process = new Process($command);
        $process->setTimeout(20);
        $slot = $this->slot();
        try {
            $process->run();
            if (!$process->isSuccessful()) throw new \RuntimeException();
            $stream = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR)['streams'][0] ?? [];
        } catch (\Throwable $e) {
            // ffprobe stderr may contain a signed URL: never return it to a visitor or log it here.
            throw new UploadException('无法确认视频内容，请检查文件完整性、服务器探测组件及网络后重试');
        } finally { flock($slot, LOCK_UN); fclose($slot); }
        $codec = $stream['codec_name'] ?? '';
        $allowed = match ($extension) {
            'hevc','h265'=>['hevc'], 'webm'=>['vp8','vp9','av1'], default=>['h264','hevc','av1','mpeg4','vp9'],
        };
        $width = (int)($stream['width'] ?? 0); $height = (int)($stream['height'] ?? 0);
        if (!in_array($codec,$allowed,true) || $width < 1 || $height < 1 || $width > 32768 || $height > 32768) {
            throw new UploadException('视频内容与格式不匹配，或视频轨道无法识别');
        }
        return [0=>$width,1=>$height,'mime'=>$mime,'codec'=>$codec];
    }
}
