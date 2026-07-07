<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class UploadPlanService
{
    private const int ONE_MIB = 1024 * 1024;
    private const int MULTIPART_THRESHOLD = 100 * self::ONE_MIB;
    private const int MINIMUM_PART_SIZE = 5 * self::ONE_MIB;
    private const int DEFAULT_PART_SIZE = 16 * self::ONE_MIB;
    private const int MAX_PARTS = 10000;
    private const int MAX_CONCURRENCY = 3;
    private const int SIGNING_WINDOW = 10;
    private const int MAX_BATCH_FILES = 10;
    private const int MAX_BATCH_BYTES = 100 * self::ONE_MIB;

    public function makePlan(User $user, array $files, ?int $parentId): array
    {
        $totalBytes = collect($files)->sum(fn ($file) => (int) $file['size']);
        $remainingBytes = max(0, $user->getMaxStorageSize() - $user->getUsedStorageSize());

        if ($totalBytes > $remainingBytes) {
            return [
                'ok' => false,
                'code' => 'storage_limit_exceeded',
                'message' => 'Not enough storage available.',
                'errors' => [[
                    'code' => 'storage_limit_exceeded',
                    'message' => 'You do not have enough storage for this upload.',
                ]],
            ];
        }

        $smallFiles = collect($files)
            ->filter(fn ($file) => (int) $file['size'] < self::MULTIPART_THRESHOLD)
            ->values();

        $multipartFiles = collect($files)
            ->filter(fn ($file) => (int) $file['size'] >= self::MULTIPART_THRESHOLD)
            ->values();

        $plan = [
            'ok' => true,
            'upload_id' => (string) str()->uuid(),
            'threshold_bytes' => self::MULTIPART_THRESHOLD,
            'default_part_size' => self::DEFAULT_PART_SIZE,
            'max_concurrency' => self::MAX_CONCURRENCY,
            'signing_window' => self::SIGNING_WINDOW,
            'small_file_batches' => $this->makeSmallFileBatches($smallFiles),
            'multipart_files' => $multipartFiles->map(function ($file) {
                $size = (int) $file['size'];
                $partSize = $this->partSizeFor($size);

                return [
                    'client_id' => $file['client_id'],
                    'upload_file_id' => (string) str()->uuid(),
                    'name' => $file['name'],
                    'size' => $size,
                    'content_type' => $file['content_type'] ?? null,
                    'last_modified' => $file['last_modified'] ?? null,
                    'relative_path' => $file['relative_path'] ?? null,
                    'part_size' => $partSize,
                    'part_count' => (int) ceil($size / $partSize),
                ];
            })->values(),
            'errors' => [],
        ];

        Cache::put("upload-plan:{$user->id}:{$plan['upload_id']}", $plan, now()->addHours(2));

        return $plan;
    }

    private function partSizeFor(int $fileSize): int
    {
        $requiredPartSize = (int) ceil($fileSize / self::MAX_PARTS);
        $partSize = max(self::MINIMUM_PART_SIZE, self::DEFAULT_PART_SIZE, $requiredPartSize);

        return (int) ceil($partSize / self::ONE_MIB) * self::ONE_MIB;
    }

    private function makeSmallFileBatches($files): array
    {
        $batches = [];
        $current = [];
        $currentBytes = 0;

        foreach ($files as $file) {
            $size = (int) $file['size'];

            if (
                count($current) >= self::MAX_BATCH_FILES ||
                $currentBytes + $size > self::MAX_BATCH_BYTES
            ) {
                $batches[] = [
                    'batch_id' => 'batch_'.(count($batches) + 1),
                    'files' => $current,
                ];

                $current = [];
                $currentBytes = 0;
            }

            $current[] = $file['client_id'];
            $currentBytes += $size;
        }

        if ($current) {
            $batches[] = [
                'batch_id' => 'batch_'.(count($batches) + 1),
                'files' => $current,
            ];
        }

        return $batches;
    }
}