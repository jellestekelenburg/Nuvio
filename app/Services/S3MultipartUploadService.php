<?php

namespace App\Services;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class S3MultipartUploadService
{
    private S3Client $client;

    private string $bucket;

    public function __construct()
    {
        $disk = Storage::disk('s3');

        if (! $disk instanceof AwsS3V3Adapter) {
            throw new RuntimeException('The s3 disk must use the AWS S3 adapter.');
        }

        $this->client = $disk->getClient();
        $this->bucket = (string) config('filesystems.disks.s3.bucket');

        if ($this->bucket === '') {
            throw new RuntimeException('Missing S3 Bucket configuration.');
        }
    }

    /**
     * @param  array<string, int|string>  $metadata
     */
    public function createMultipartUpload(string $key, ?string $contentType, array $metadata = []): string
    {
        $args = [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Metadata' => collect($metadata)
                ->mapWithKeys(fn ($value, $key) => [(string) $key => (string) $value])
                ->all(),
        ];

        if ($contentType) {
            $args['ContentType'] = $contentType;
        }

        $result = $this->client->createMultipartUpload($args);

        return (string) $result['UploadId'];
    }

    public function presignUploadPart(
        string $key,
        string $s3UploadId,
        int $partNumber,
        int $expiresInSeconds = 900,
    ): string {
        $command = $this->client->getCommand('UploadPart', [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'UploadId' => $s3UploadId,
            'PartNumber' => $partNumber,
        ]);

        $request = $this->client->createPresignedRequest(
            $command,
            "+{$expiresInSeconds} seconds",
        );

        return (string) $request->getUri();
    }

    /**
     * @param  list<array{part_number: int|string, etag: string}>  $parts
     */
    public function completeMultiPartUpload(string $key, string $s3UploadId, array $parts): void
    {
        $this->client->completeMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'UploadId' => $s3UploadId,
            'MultipartUpload' => [
                'Parts' => collect($parts)->map(fn (array $part) => [
                    'PartNumber' => (int) $part['part_number'],
                    'ETag' => trim((string) $part['etag']),
                ])->values()->all(),
            ],
        ]);
    }

    public function objectSize(string $key): int
    {
        $result = $this->client->headObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        return (int) $result['ContentLength'];
    }

    public function abortMultipartUpload(string $key, string $s3UploadId): void
    {
        try {
            $this->client->abortMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $s3UploadId,
            ]);
        } catch (S3Exception $exception) {
            if ($exception->getAwsErrorCode() === 'NoSuchUpload') {
                return;
            }

            throw $exception;
        }
    }
}
