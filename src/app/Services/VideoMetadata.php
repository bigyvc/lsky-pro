<?php
namespace App\Services;

use App\Exceptions\UploadException;
use Symfony\Component\Process\Process;

/** Metadata inspection only: never transcode, generate posters or trust a renamed extension. */
class VideoMetadata
{
    public const EXTENSIONS = ['mp4', 'mov', 'webm', 'hevc', 'h265', 'm4v', 'mkv', 'avi', 'wmv', 'flv', 'ts', 'm2ts', 'mts', '3gp', 'm3u8'];
    public static function isVideo(string $extension): bool { return in_array(strtolower($extension), self::EXTENSIONS, true); }
    public static function mime(string $extension): string
    {
        return match (strtolower($extension)) {
            'mp4'=>'video/mp4', 'mov'=>'video/quicktime', 'webm'=>'video/webm',
            'hevc','h265'=>'video/h265', 'm4v'=>'video/mp4', 'mkv'=>'video/x-matroska',
            'avi'=>'video/x-msvideo', 'wmv'=>'video/x-ms-wmv', 'flv'=>'video/x-flv',
            'm3u8'=>'application/vnd.apple.mpegurl', 'ts','m2ts','mts'=>'video/mp2t', '3gp'=>'video/3gpp', default=>throw new UploadException('不支持的视频类型'),
        };
    }

    /** Only playlist text is validated; segment/key URLs are never fetched by the server. */
    public function playlist(string $text): array
    {
        if (strlen($text) > 1048576 || str_contains($text, "\0") || !preg_match('//u', $text)) {
            throw new UploadException('M3U8须为UTF-8文本，且不超过1MiB');
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $lines = preg_split('/\r\n|\n|\r/', trim($text));
        if (($lines[0] ?? '') !== '#EXTM3U' || !preg_match('/^#EXT(?:INF:|-X-STREAM-INF:)/m', str_replace("\r", '', $text))) {
            throw new UploadException('不是有效的HLS播放列表');
        }
        $references = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $uris = [];
            if ($line[0] !== '#') { $uris[] = $line; $references++; }
            else {
                if (str_starts_with($line, '#EXT-X-DEFINE:')) throw new UploadException('M3U8暂不支持变量引用');
                // Also validate KEY/MAP/MEDIA/I-FRAME URI attributes.
                if (preg_match_all('/(?:^|[:,])URI=("[^"]*"|[^,]*)/', $line, $matches)) {
                    foreach ($matches[1] as $value) {
                        if (!preg_match('/^"([^"]+)"$/D', $value, $quoted)) throw new UploadException('M3U8的URI必须使用双引号');
                        $uris[] = $quoted[1];
                    }
                }
            }
            foreach ($uris as $uri) {
                $parts = parse_url($uri);
                if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
                    || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20<>{}]/', $uri)) {
                    throw new UploadException('M3U8只支持完整HTTPS地址，不支持相对路径或本地文件');
                }
                $host = trim($parts['host'], '[]');
                if ($host === 'localhost' || str_ends_with($host, '.localhost')
                    || (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))) {
                    throw new UploadException('M3U8不支持本地网络地址');
                }
            }
        }
        if (!$references) throw new UploadException('M3U8缺少视频分片或子列表地址');
        return [0=>0, 1=>0, 'mime'=>self::mime('m3u8'), 'codec'=>'hls'];
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
        if ($extension === 'm3u8') {
            // Never let ffprobe follow playlist URLs. Remote R2 text is read by the gateway.
            if ($remote || !is_file($input)) throw new UploadException('播放列表读取方式无效');
            return $this->playlist((string)file_get_contents($input, false, null, 0, 1048577));
        }
        // Explicit demuxers prevent a renamed playlist from opening arbitrary protocols/URLs.
        $format = match ($extension) {
            'mp4','mov','m4v','3gp'=>'mov', 'webm','mkv'=>'matroska', 'avi'=>'avi',
            'wmv'=>'asf', 'flv'=>'flv', 'ts','m2ts','mts'=>'mpegts', default=>'hevc',
        };
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
            'hevc','h265'=>['hevc'], 'webm'=>['vp8','vp9','av1'], default=>['h264','hevc','av1','mpeg4','vp8','vp9','mjpeg','mpeg1video','mpeg2video',
                'wmv1','wmv2','wmv3','vc1','flv1','vp6f','h263','h263p','msmpeg4v2','msmpeg4v3','dvvideo','ffv1','huffyuv','rawvideo'],
        };
        $width = (int)($stream['width'] ?? 0); $height = (int)($stream['height'] ?? 0);
        if (!in_array($codec,$allowed,true) || $width < 1 || $height < 1 || $width > 32768 || $height > 32768) {
            throw new UploadException('视频内容与格式不匹配，或视频轨道无法识别');
        }
        return [0=>$width,1=>$height,'mime'=>$mime,'codec'=>$codec];
    }
}
