<?php

namespace App\Services;

use App\Models\File;
use App\Models\MultipartUpload;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UploadMultipartService
{
    private const int SIGNED_PART_URL_TTL_SECONDS = 900;

    private const int MAX_SIGNED_PARTS_PER_REQUEST = 20;

    public function __construct(
        private readonly S3MultipartUploadService $s3MultipartUploadService,
        private readonly RegisterStoredS3File $registerStoredS3File,
        private readonly StorageUserService $storageUserService,
        private readonly UploadTargetFolderResolver $targetFolderResolver,
    ) {}

    public function initiate(
        User $user,
        string $uploadId,
        string $uploadFileId,
        ?int $parentId,
    ): array {
        $plan = Cache::get("upload-plan:{$user->id}:{$uploadId}");

        if (! $plan) {
            return $this->result([
                'ok' => false,
                'code' => 'upload_plan_not_found',
                'message' => 'Upload plan not found or expired.',
            ], 404);
        }

        $plannedFile = collect($plan['multipart_files'] ?? [])
            ->firstWhere('upload_file_id', $uploadFileId);

        if (! $plannedFile) {
            return $this->result([
                'ok' => false,
                'code' => 'multipart_file_not_found',
                'message' => 'Multipart file was not found in this upload plan.',
            ], 404);
        }

        $existingUpload = MultipartUpload::query()
            ->where('user_id', $user->id)
            ->where('upload_id', $uploadId)
            ->where('upload_file_id', $uploadFileId)
            ->first();

        if ($existingUpload) {
            if ($existingUpload->isActive()) {
                return $this->result($this->uploadStateBody(
                    upload: $existingUpload,
                    plan: $plan,
                ));
            }

            return $this->result([
                'ok' => false,
                'code' => 'multipart_upload_not_active',
                'message' => 'This multipart upload can no longer be initiated.',
            ], 409);
        }

        $user->refresh();

        $size = (int) $plannedFile['size'];
        $reservedBytes = $this->activeReservedBytes($user);
        $remainingBytes = max(
            0,
            $user->getMaxStorageSize() - $user->getUsedStorageSize() - $reservedBytes,
        );

        if ($size > $remainingBytes) {
            return $this->result([
                'ok' => false,
                'code' => 'storage_limit_exceeded',
                'message' => 'You do not have enough storage for this multipart upload.',
            ], 422);
        }

        $parent = $this->resolveParent($user, $parentId);
        $s3Key = $this->makeS3Key($user, (string) $plannedFile['name']);

        $s3UploadId = $this->s3MultipartUploadService->createMultipartUpload(
            key: $s3Key,
            contentType: $plannedFile['content_type'] ?? null,
            metadata: [
                'user_id' => $user->id,
                'upload_id' => $uploadId,
                'upload_file_id' => $uploadFileId,
                'original_name_base64' => base64_encode((string) $plannedFile['name']),
            ],
        );

        $upload = MultipartUpload::query()->create([
            'upload_id' => $uploadId,
            'upload_file_id' => $uploadFileId,
            'user_id' => $user->id,
            'parent_id' => $parent->id,
            'client_id' => $plannedFile['client_id'],
            'name' => $plannedFile['name'],
            'relative_path' => $plannedFile['relative_path'] ?? null,
            'content_type' => $plannedFile['content_type'] ?? null,
            'size' => $size,
            'part_size' => (int) $plannedFile['part_size'],
            'part_count' => (int) $plannedFile['part_count'],
            'reserved_bytes' => $size,
            's3_key' => $s3Key,
            's3_upload_id' => $s3UploadId,
            'status' => MultipartUpload::STATUS_INITIATED,
            'initiated_at' => now(),
        ]);

        return $this->result($this->uploadStateBody(
            upload: $upload,
            plan: $plan,
        ), 201);
    }

    public function signParts(
        User $user,
        string $uploadId,
        string $uploadFileId,
        array $partNumbers,
    ): array {
        $upload = MultipartUpload::query()
            ->where('user_id', $user->id)
            ->where('upload_id', $uploadId)
            ->where('upload_file_id', $uploadFileId)
            ->first();

        if (! $upload) {
            return $this->result([
                'ok' => false,
                'code' => 'multipart_upload_not_found',
                'message' => 'Multipart upload was not found.',
            ], 404);
        }

        if (! $upload->isActive()) {
            return $this->result([
                'ok' => false,
                'code' => 'multipart_upload_not_active',
                'message' => 'This multipart upload can no longer sign parts.',
            ], 409);
        }

        $partNumbers = collect($partNumbers)
            ->map(fn ($partNumber) => (int) $partNumber)
            ->values();

        if ($partNumbers->count() > self::MAX_SIGNED_PARTS_PER_REQUEST) {
            return $this->result([
                'ok' => false,
                'code' => 'too_many_parts_requested',
                'message' => 'Too many parts requested for signing.',
            ], 422);
        }

        $invalidPartNumber = $partNumbers->first(
            fn (int $partNumber) => $partNumber < 1 || $partNumber > $upload->part_count,
        );

        if ($invalidPartNumber !== null) {
            return $this->result([
                'ok' => false,
                'code' => 'invalid_part_number',
                'message' => 'One or more part numbers are outside the upload range.',
            ], 422);
        }

        if ($upload->status === MultipartUpload::STATUS_INITIATED) {
            $upload->forceFill(['status' => MultipartUpload::STATUS_UPLOADING])->save();
        }

        $parts = $partNumbers->map(function (int $partNumber) use ($upload) {
            $start = ($partNumber - 1) * $upload->part_size;
            $end = min($start + $upload->part_size, $upload->size);

            return [
                'part_number' => $partNumber,
                'url' => $this->s3MultipartUploadService->presignUploadPart(
                    key: $upload->s3_key,
                    s3UploadId: $upload->s3_upload_id,
                    partNumber: $partNumber,
                    expiresInSeconds: self::SIGNED_PART_URL_TTL_SECONDS,
                ),
                'start' => $start,
                'end' => $end,
            ];
        })->all();

        return $this->result([
            'ok' => true,
            'upload_id' => $upload->upload_id,
            'upload_file_id' => $upload->upload_file_id,
            'parts' => $parts,
            'expires_in' => self::SIGNED_PART_URL_TTL_SECONDS,
            'expires_at' => now()->addSeconds(self::SIGNED_PART_URL_TTL_SECONDS)->toIso8601String(),
        ]);
    }

    public function complete(
        User $user,
        string $uploadId,
        string $uploadFileId,
        array $parts,
    ): array {
        $upload = MultipartUpload::query()
            ->where('user_id', $user->id)
            ->where('upload_id', $uploadId)
            ->where('upload_file_id', $uploadFileId)
            ->first();

        if (! $upload) {
            return $this->result(['ok' => false, 'message' => 'Multipart upload was not found.'], 404);
        }

        $upload->loadMissing('completedFile');

        if ($upload->status === MultipartUpload::STATUS_COMPLETED && $upload->completedFile) {
            return $this->result([
                'ok' => true,
                'file' => $this->completedFileBody($upload),
            ]);
        }

        if (! $upload->isActive()) {
            return $this->result(['ok' => false, 'message' => 'This multipart upload can not be completed.'], 409);
        }

        $normalizedParts = collect($parts)
            ->map(fn (array $part) => [
                'part_number' => (int) $part['part_number'],
                'etag' => trim((string) $part['etag']),
            ])
            ->sortBy('part_number')
            ->values();

        if ($normalizedParts->count() !== $upload->part_count) {
            return $this->result(['ok' => false, 'message' => 'Not all parts were provided.'], 422);
        }

        $expectedPartNumbers = range(1, $upload->part_count);

        if ($normalizedParts->pluck('part_number')->all() !== $expectedPartNumbers) {
            return $this->result(['ok' => false, 'message' => 'Part numbers must exactly match the upload plan.'], 422);
        }

        $this->s3MultipartUploadService->completeMultiPartUpload(
            key: $upload->s3_key,
            s3UploadId: $upload->s3_upload_id,
            parts: $normalizedParts->all(),
        );

        if ($this->s3MultipartUploadService->objectSize($upload->s3_key) !== $upload->size) {
            $upload->forceFill(['status' => MultipartUpload::STATUS_FAILED])->save();

            return $this->result(['ok' => false, 'message' => 'Completed S3 object size does not match the upload plan.'], 500);
        }

        $file = DB::transaction(function () use ($user, $upload) {
            $rootParent = $this->resolveParent($user, $upload->parent_id);
            $targetParent = $this->targetFolderResolver->resolve(
                user: $user,
                rootParent: $rootParent,
                relativePath: $upload->relative_path,
            );

            $file = $this->registerStoredS3File->handle(
                user: $user,
                parent: $targetParent,
                s3Key: $upload->s3_key,
                name: $upload->name,
                mime: $upload->content_type,
                size: $upload->size,
            );

            $this->storageUserService->addUsage($user, $upload->size);

            $upload->forceFill([
                'status' => MultipartUpload::STATUS_COMPLETED,
                'completed_file_id' => $file->id,
                'reserved_bytes' => 0,
                'completed_at' => now(),
            ])->save();

            return $file;
        });

        return $this->result([
            'ok' => true,
            'file' => [
                'client_id' => $upload->client_id,
                'file_id' => $file->id,
                'name' => $file->name,
                'size' => $file->size,
                'status' => 'done',
            ],
        ]);
    }

    public function abort(
        User $user,
        string $uploadId,
        string $uploadFileId,
    ): array {
        $upload = MultipartUpload::query()
            ->where('user_id', $user->id)
            ->where('upload_id', $uploadId)
            ->where('upload_file_id', $uploadFileId)
            ->first();

        if (! $upload) {
            return $this->result([
                'ok' => false,
                'code' => 'multipart_upload_not_found',
                'message' => 'Multipart upload was not found.',
            ], 404);
        }

        if ($upload->status === MultipartUpload::STATUS_COMPLETED) {
            return $this->result([
                'ok' => true,
                'status' => $upload->status,
            ]);
        }

        if ($upload->status === MultipartUpload::STATUS_ABORTED) {
            return $this->result([
                'ok' => true,
                'status' => $upload->status,
            ]);
        }

        if ($upload->status === MultipartUpload::STATUS_FAILED) {
            return $this->result([
                'ok' => true,
                'status' => $upload->status,
            ]);
        }

        $this->s3MultipartUploadService->abortMultipartUpload(
            key: $upload->s3_key,
            s3UploadId: $upload->s3_upload_id,
        );

        $upload->forceFill([
            'status' => MultipartUpload::STATUS_ABORTED,
            'reserved_bytes' => 0,
            'aborted_at' => now(),
        ])->save();

        return $this->result([
            'ok' => true,
            'status' => $upload->status,
        ]);
    }

    private function uploadStateBody(MultipartUpload $upload, array $plan): array
    {
        return [
            'ok' => true,
            'upload_id' => $upload->upload_id,
            'upload_file_id' => $upload->upload_file_id,
            'status' => $upload->status,
            'part_size' => $upload->part_size,
            'part_count' => $upload->part_count,
            'max_concurrency' => (int) ($plan['max_concurrency'] ?? 3),
            'signing_window' => (int) ($plan['signing_window'] ?? 10),
        ];
    }

    private function activeReservedBytes(User $user): int
    {
        return (int) MultipartUpload::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                MultipartUpload::STATUS_INITIATED,
                MultipartUpload::STATUS_UPLOADING,
            ])
            ->sum('reserved_bytes');
    }

    private function resolveParent(User $user, ?int $parentId): File
    {
        if ($parentId) {
            return File::query()
                ->where('id', $parentId)
                ->where('created_by', $user->id)
                ->where('is_folder', true)
                ->firstOrFail();
        }

        return File::query()
            ->where('created_by', $user->id)
            ->whereIsRoot()
            ->firstOrFail();
    }

    private function makeS3Key(User $user, string $originalName): string
    {
        return sprintf(
            'files/%d/%s/%s/%s',
            $user->id,
            now()->format('Y/m'),
            (string) str()->uuid(),
            $this->safeFilename($originalName),
        );
    }

    private function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $filename = pathinfo($name, PATHINFO_FILENAME);

        $safeFilename = Str::slug($filename) ?: 'file';
        $safeExtension = Str::slug($extension);

        if (! $safeExtension) {
            return $safeFilename;
        }

        return "{$safeFilename}.{$safeExtension}";
    }

    private function result(array $body, int $status = 200): array
    {
        return [
            'body' => $body,
            'status' => $status,
        ];
    }

    private function completedFileBody(MultipartUpload $upload): array
    {
        return [
            'client_id' => $upload->client_id,
            'file_id' => $upload->completedFile->id,
            'name' => $upload->completedFile->name,
            'size' => $upload->completedFile->size,
            'status' => 'done',
        ];
    }
}
