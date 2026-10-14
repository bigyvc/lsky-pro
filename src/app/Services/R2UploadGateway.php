<?php

namespace App\Services;

use App\Enums\Strategy\S3Option;
use App\Enums\Strategy\MinioOption;
use App\Enums\StrategyKey;
use App\Models\Strategy;
use Aws\S3\S3Client;

/** All data-plane PUTs go from the browser to R2; PHP sends only control requests. */
class R2UploadGateway
{
    private S3Client $client;
    private string $bucket;

    public function __construct(Strategy $strategy)
    {
        $config = $strategy->configs;
        $this->bucket = $config->get(S3Option::Bucket);
        $this->client = new S3Client([
            'credentials' => [
                'key' => $config->get($strategy->key === StrategyKey::Minio ? MinioOption::AccessKey : S3Option::AccessKeyId),
                'secret' => $config->get($strategy->key === StrategyKey::Minio ? MinioOption::SecretKey : S3Option::SecretAccessKey),
            ],
            'endpoint' => $config->get(S3Option::Endpoint), 'region' => $config->get(S3Option::Region) ?: 'auto',
            'version' => '2006-03-01', 'use_path_style_endpoint' => true,
            'http' => ['connect_timeout' => 10, 'timeout' => 30], 'retries' => 2,
            'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required',
        ]);
    }

    public function start(string $key, string $mime, string $id): string
    {
        return (string) $this->client->createMultipartUpload([
            'Bucket' => $this->bucket, 'Key' => $key, 'ContentType' => $mime,
            'Metadata' => ['lsky-direct-id' => $id],
        ])['UploadId'];
    }

    public function sign(string $key, string $uploadId, int $number, int $size, string $md5): array
    {
        $command = $this->client->getCommand('UploadPart', [
            'Bucket' => $this->bucket, 'Key' => $key, 'UploadId' => $uploadId, 'PartNumber' => $number,
            'ContentLength' => $size, 'ContentMD5' => base64_encode(hex2bin($md5)),
        ]);
        $request = $this->client->createPresignedRequest($command, '+30 minutes');
        return ['url' => (string) $request->getUri(), 'md5' => base64_encode(hex2bin($md5)), 'size' => $size];
    }

    public function parts(string $key, string $uploadId): array
    {
        // At most eleven 50MiB parts for the 512MiB application limit.
        return $this->client->listParts(['Bucket' => $this->bucket, 'Key' => $key, 'UploadId' => $uploadId, 'MaxParts' => 1000])['Parts'] ?? [];
    }

    public function complete(string $key, string $uploadId, array $parts): void
    {
        $this->client->completeMultipartUpload(['Bucket' => $this->bucket, 'Key' => $key, 'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => array_map(fn ($part) => ['PartNumber' => $part['PartNumber'], 'ETag' => $part['ETag']], $parts)]]);
    }

    public function head(string $key): array
    {
        return $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $key])->toArray();
    }

    public function header(string $key): string
    {
        $body = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'Range' => 'bytes=0-65535'])['Body'];
        try { return $body->read(65536); }
        finally { $body->close(); }
    }

    public function abort(string $key, string $uploadId): void
    {
        $this->client->abortMultipartUpload(['Bucket' => $this->bucket, 'Key' => $key, 'UploadId' => $uploadId]);
    }

    public function delete(string $key): void
    {
        $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
    }
}
