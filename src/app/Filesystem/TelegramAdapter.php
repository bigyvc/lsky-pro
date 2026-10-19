<?php

namespace App\Filesystem;

use App\Models\TelegramFile;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\Facades\Cache;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use RuntimeException;
use Throwable;

/**
 * Telegram 存储适配器：通过 Bot API 把文件以 document 形式发到指定频道保存。
 *
 * 设计说明：
 *  - Telegram 不是按路径寻址的存储，path -> file_id / message_id 的映射
 *    持久化在 telegram_files 表（按 strategy_id 隔离）。
 *  - 上传固定用 sendDocument（不用 sendPhoto），避免 Telegram 压缩转码，
 *    保证图床存的是原图。
 *  - Bot Token 绝不能出现在公开 URL 里：TG 图片始终走应用输出，
 *    无需依赖用户手动开启「原图保护」。
 *  - 官方 Bot API 上传上限50MB、下载上限20MB；自建 local API 支持大图和共享目录。getFile 拿到的 file_path 缓存 50 分钟，
 *    减少每次读取时的 API 调用。
 */
class TelegramAdapter implements FilesystemAdapter
{
    protected HttpClient $http;
    protected string $apiBase;

    public function __construct(
        protected string $botToken,
        protected string $chatId,
        protected int $strategyId,
        string $apiBase = 'https://api.telegram.org',
        protected int $timeout = 120,
        protected string $localRoot = '',
    ) {
        $this->apiBase = rtrim($apiBase, '/');
        $this->http = new HttpClient(['timeout' => $this->timeout]);
    }

    public function fileExists(string $path): bool
    {
        return $this->record($path) !== null;
    }

    public function directoryExists(string $path): bool
    {
        // Telegram 侧没有目录概念，只有逻辑上的根
        return $path === '' || $path === '/';
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw UnableToWriteFile::atLocation($path, '无法创建临时流');
        }
        fwrite($stream, $contents);
        rewind($stream);
        try {
            $this->writeStream($path, $stream, $config);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        if (! is_resource($contents)) {
            throw UnableToWriteFile::atLocation($path, '上传内容必须是资源流');
        }

        $stat = @fstat($contents);
        $size = ($stat !== false && isset($stat['size'])) ? (int) $stat['size'] : 0;
        $mimeType = (string) ($config->get('mimetype') ?? $config->get('mime_type') ?? '');
        // A human-readable channel label is independent of the persistent mapping path.
        $filename = (string) ($config->get('telegram_filename') ?? basename($path));
        $filename = str_replace(['/', chr(92)], '_', $filename);
        $filename = preg_replace('/[\x00-\x1f\x7f]/u', '_', $filename) ?? '';
        if ($filename === '' || $filename === '.' || $filename === '..') $filename = basename($path);


        // 先上传，成功后再替换映射、清理旧文件：上传失败时旧文件不受影响
        $old = $this->record($path);

        try {
            $result = $this->callApi('sendDocument', [
                'multipart' => [
                    ['name' => 'chat_id', 'contents' => $this->chatId],
                    [
                        'name' => 'document',
                        'contents' => $contents,
                        'filename' => $filename,
                    ],
                    ['name' => 'disable_notification', 'contents' => 'true'],
                ],
            ]);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }

        $document = $result['document'] ?? null;
        if ($size > 0 && isset($document['file_size']) && (int)$document['file_size'] !== $size) {
            if (isset($result['message_id'])) $this->deleteRemoteMessage((int)$result['message_id'], $path, true);
            throw UnableToWriteFile::atLocation($path, 'Telegram 返回的文件大小与上传不一致');
        }
        if (! isset($result['message_id'], $document['file_id'])) {
            if (isset($result['message_id'])) $this->deleteRemoteMessage((int)$result['message_id'], $path, true);
            throw UnableToWriteFile::atLocation($path, 'Telegram 未返回 file_id');
        }

        try {
            TelegramFile::updateOrCreate(
                ['strategy_id' => $this->strategyId, 'path' => $path],
                [
                    'file_id' => $document['file_id'],
                    'message_id' => $result['message_id'],
                    'size' => $size,
                    'mime_type' => $mimeType,
                ],
            );
        } catch (Throwable $e) {
            // Do not report success or replace the old mapping when local persistence fails.
            $this->deleteRemoteMessage((int) $result['message_id'], $path, true);
            throw UnableToWriteFile::atLocation($path, 'TG 文件已发送但映射保存失败，请检查数据库与磁盘状态');
        }

        if ($old) {
            // 旧频道消息尽力清理，删不掉也只是频道里多一条孤儿消息，不影响映射正确性
            $this->deleteRemoteMessage($old->message_id, $path, true);
        }
    }

    /** JPEG photo albums, kept separate from byte-preserving document uploads. */
    public function sendPhotoBatch(array $files): array
    {
        if (count($files) < 1 || count($files) > 10) throw new RuntimeException('照片组需要1至10张照片');
        $media=[]; $parts=[['name'=>'chat_id','contents'=>$this->chatId], ['name'=>'disable_notification','contents'=>'true']];
        foreach (array_values($files) as $i=>$item) {
            $parts[]=['name'=>'p'.$i,'contents'=>$item['stream'],'filename'=>$item['filename']];
            $media[]=['type'=>'photo','media'=>'attach://p'.$i];
        }
        if (count($files) === 1) {
            $parts[2]['name']='photo';
            return [$this->callApi('sendPhoto',['multipart'=>$parts])];
        }
        $parts[]=['name'=>'media','contents'=>json_encode($media, JSON_THROW_ON_ERROR)];
        return $this->callApi('sendMediaGroup',['multipart'=>$parts]);
    }

    public function discardPhotoMessages(array $ids): void
    {
        foreach (array_unique($ids) as $id) if (is_int($id) && $id > 0) {
            $this->deleteRemoteMessage($id, 'photo-import', true);
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);
        try { $contents = stream_get_contents($stream); }
        finally { if (is_resource($stream)) fclose($stream); }
        if ($contents === false) {
            throw UnableToReadFile::fromLocation($path, '读取文件流失败');
        }

        return $contents;
    }

    public function readStream(string $path)
    {
        $record = $this->record($path);
        if (! $record) {
            throw UnableToReadFile::fromLocation($path, '文件不存在');
        }

        $cacheLock = null;
        try {
            // Hold until a shared-locked stream is open; host cleanup takes the exclusive lock.
            if ($this->localRoot !== '') $cacheLock = \App\Services\TelegramCacheRegistry::lock();
            $filePath = $this->resolveFilePath($record->file_id);
            if (str_starts_with($filePath, '/')) {
                $root = realpath($this->localRoot);
                clearstatcache(true, $filePath);
                $file = realpath($filePath);
                if (!$file || !is_file($file)) {
                    // Native or host cleanup may invalidate the 50-minute path cache.
                    Cache::forget($this->filePathCacheKey($record->file_id));
                    $filePath = $this->resolveFilePath($record->file_id);
                    clearstatcache(true, $filePath);
                    $file = realpath($filePath);
                }
                if (!$root || $root === '/' || !$file || !str_starts_with($file, rtrim($root, '/').'/')
                    || !is_file($file) || !is_readable($file)) throw new RuntimeException('TG 本地文件不在配置的可读共享目录内');
                $stream = fopen($file, 'rb');
                if (!$stream) throw new RuntimeException('无法打开 TG 本地文件');
                if (!flock($stream, LOCK_SH)) { fclose($stream); throw new RuntimeException('无法锁定 TG 本地文件'); }
                try { \App\Services\TelegramCacheRegistry::register($root, $file); }
                catch (Throwable $e) { fclose($stream); throw $e; }
                return $stream;
            }
            $response = $this->http->get($this->fileUrl($filePath), ['stream' => true]);

            return $response->getBody()->detach();
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, str_replace($this->botToken, '[redacted]', $e->getMessage()));
        } finally {
            if (is_resource($cacheLock)) { flock($cacheLock, LOCK_UN); fclose($cacheLock); }
        }
    }

    public function delete(string $path): void
    {
        $record = $this->record($path);
        if (! $record) {
            return;
        }

        $this->deleteRemoteMessage($record->message_id, $path);
        $record->delete();
    }

    /**
     * 删除频道消息。$bestEffort 为 true 时任何失败都只吞掉（用于覆盖写时的旧消息清理）；
     * 否则「消息已不存在」类错误吞掉，其余错误抛 UnableToDeleteFile。
     */
    protected function deleteRemoteMessage(int $messageId, string $path, bool $bestEffort = false): void
    {
        try {
            $this->callApi('deleteMessage', ['query' => [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
            ]]);
        } catch (Throwable $e) {
            if ($bestEffort) {
                return;
            }
            $message = $e->getMessage();
            // 消息已不存在 / 无权删除时，只清理本地映射，不视为失败
            if (! str_contains($message, 'message to delete not found')
                && ! str_contains($message, "message can't be deleted")) {
                throw UnableToDeleteFile::atLocation($path, $message, $e);
            }
        }
    }

    public function deleteDirectory(string $path): void
    {
        // Telegram 侧没有目录概念
    }

    public function createDirectory(string $path, Config $config): void
    {
        // Telegram 侧没有目录概念
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // Telegram 侧没有可见性概念
    }

    public function visibility(string $path): FileAttributes
    {
        $this->requireRecord($path, 'visibility');

        return new FileAttributes($path, null, Visibility::PUBLIC);
    }

    public function mimeType(string $path): FileAttributes
    {
        $record = $this->requireRecord($path, 'mimetype');

        return new FileAttributes($path, null, null, null, $record->mime_type ?: null);
    }

    public function lastModified(string $path): FileAttributes
    {
        $record = $this->requireRecord($path, 'lastModified');

        return new FileAttributes($path, null, null, $record->updated_at->getTimestamp());
    }

    public function fileSize(string $path): FileAttributes
    {
        $record = $this->requireRecord($path, 'fileSize');

        return new FileAttributes($path, (int) $record->size);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return TelegramFile::query()
            ->where('strategy_id', $this->strategyId)
            ->orderBy('path')
            ->get()
            ->map(fn (TelegramFile $file) => new FileAttributes(
                $file->path,
                (int) $file->size,
                Visibility::PUBLIC,
                $file->updated_at->getTimestamp(),
                $file->mime_type ?: null,
            ))
            ->all();
    }

    public function move(string $source, string $destination, Config $config): void
    {
        if ($source === $destination) return;
        try {
            $this->copy($source, $destination, $config);
            $this->delete($source);
        } catch (Throwable $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        if ($source === $destination) return;
        // Telegram 没有服务端复制，只能下载再上传
        try {
            $stream = $this->readStream($source);
            try { $this->writeStream($destination, $stream, $config); }
            finally { if (is_resource($stream)) fclose($stream); }
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    protected function record(string $path): ?TelegramFile
    {
        return TelegramFile::query()
            ->where('strategy_id', $this->strategyId)
            ->where('path', $path)
            ->first();
    }

    protected function requireRecord(string $path, string $type): TelegramFile
    {
        $record = $this->record($path);
        if (! $record) {
            throw UnableToRetrieveMetadata::create($path, $type, '文件不存在');
        }

        return $record;
    }

    /**
     * 调用 Bot API，返回解码后的 result；失败抛 RuntimeException。
     */
    protected function callApi(string $method, array $options = []): array
    {
        $status=0; $data=null; $errno=0;
        try {
            // Parse Telegram's HTTP 4xx/5xx JSON rather than letting Guzzle hide it.
            // Never log the raw response, request URL, exception chain or credentials.
            $options['http_errors']=false;
            $response=$this->http->post($this->apiBase.'/bot'.$this->botToken.'/'.$method,$options);
            $status=$response->getStatusCode();
            $data=json_decode($response->getBody()->read(65536),true);
        } catch (Throwable $e) {
            if (method_exists($e,'getHandlerContext')) {
                $context=$e->getHandlerContext();$errno=(int)($context['errno']??0);
            }
            // Custom handlers can still throw a response-bearing RequestException.
            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $response=$e->getResponse();$status=$response->getStatusCode();
                $data=json_decode($response->getBody()->read(65536),true);
            }
            throw new RuntimeException($this->apiFailure($method,$status,$data,$errno));
        }
        if ($status<200 || $status>=300 || !is_array($data) || ($data['ok']??false)!==true) {
            throw new RuntimeException($this->apiFailure($method,$status,$data,$errno));
        }
        $result=$data['result']??null;
        if (is_array($result)) return $result;
        // deleteMessage returns true, not a Message object. Callers ignore its result.
        if ($method==='deleteMessage' && $result===true) return ['ok'=>true];
        throw new RuntimeException('Telegram Bot API返回结构异常 ['.$method.']');
    }

    private function apiFailure(string $method,int $status,$data,int $errno): string
    {
        $data=is_array($data)?$data:[];
        $code=is_numeric($data['error_code']??null)?(int)$data['error_code']:0;
        $parameters=is_array($data['parameters']??null)?$data['parameters']:[];
        $retry=filter_var($parameters['retry_after']??0,FILTER_VALIDATE_INT);
        $retry=$retry!==false && $retry>=0 && $retry<=2147483647?$retry:0;
        $description=is_string($data['description']??null)?substr($data['description'],0,4096):'';
        $category=$errno===28?'TIMEOUT':(($errno || $status===0)?'TRANSPORT_ERROR':($status>=400?'HTTP_ERROR':'INVALID_RESPONSE'));
        // Only fixed labels are exposed. Telegram descriptions can echo input or URLs.
        $patterns=[
            'RATE_LIMIT'=>'~too many requests|retry after|flood~i',
            'PHOTO_INVALID_DIMENSIONS'=>'~PHOTO_INVALID_DIMENSIONS~i',
            'IMAGE_PROCESS_FAILED'=>'~IMAGE_PROCESS_FAILED|failed to process (?:the )?photo~i',
            'PHOTO_INVALID'=>'~PHOTO_INVALID|invalid photo~i',
            'MEDIA_INVALID'=>'~MEDIA_INVALID|can.t parse (?:inputmedia|media)|media group.*invalid~i',
            'FILE_TOO_BIG'=>'~file is too big|FILE_TOO_BIG|request entity too large~i',
            'CHAT_NOT_FOUND'=>'~chat not found~i',
            'CHAT_WRITE_FORBIDDEN'=>'~CHAT_WRITE_FORBIDDEN|not enough rights|not a member|bot was kicked|need administrator~i',
            'UNAUTHORIZED'=>'~unauthorized~i',
            'MESSAGE_DELETE_FAILED'=>'~message to delete not found|message can.t be deleted~i',
        ];
        foreach ($patterns as $name=>$pattern) if (preg_match($pattern,$description)) {$category=$name;break;}
        if ($status===429 || $code===429) $category='RATE_LIMIT';
        if (preg_match('~failed to send message #(\d{1,2})~i',$description,$match)) $category.=':item'.(int)$match[1];
        return 'Telegram Bot API请求失败 ['.$method.']：HTTP='.$status.' TG='.$code.' curl='.$errno
            .' category='.$category.($retry?' retry_after='.$retry.'s':'');
    }

    /**
     * file_id -> file_path（getFile 结果缓存 50 分钟，file_path 本身至少 1 小时有效）。
     */
    protected function resolveFilePath(string $fileId): string
    {
        $cacheKey = $this->filePathCacheKey($fileId);
        $filePath = Cache::get($cacheKey);
        if (! $filePath) {
            try {
                $result = $this->callApi('getFile', ['query' => ['file_id' => $fileId]]);
            } catch (Throwable $e) {
                throw new RuntimeException('获取 Telegram 文件路径失败：' . $e->getMessage(), 0, $e);
            }
            $filePath = $result['file_path'] ?? null;
            if (! $filePath) {
                throw new RuntimeException('Telegram 未返回 file_path');
            }
            Cache::put($cacheKey, $filePath, 50 * 60);
        }

        return $filePath;
    }

    protected function filePathCacheKey(string $fileId): string
    {
        return 'telegram_file_path:' . hash('sha256', $this->strategyId.'|'.$this->apiBase.'|'.$this->botToken.'|'.$fileId);
    }

    protected function fileUrl(string $filePath): string
    {
        return $this->apiBase . '/file/bot' . $this->botToken . '/' . $filePath;
    }
}
