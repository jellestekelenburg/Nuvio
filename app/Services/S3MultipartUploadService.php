<?php

namespace App\Services;

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
            'PartNumber' => $partNumber
        ]);

        $request = $this->client->createPresignedRequest(
            $command,
            "+{$expiresInSeconds} seconds",
        );

        return (string) $request->getUri();
    }
}