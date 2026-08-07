<?php

namespace App\Services;

use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class UploadPlanService
{
    // Identifies the upload plan format consumed by the client.
    public const int VERSION = 2;

    // Number of bytes in one mebibyte; used to define byte-based limits below.
    private const int ONE_MIB = 1024 * 1024;

    // Files at or above this size use an S3 multipart upload.
    private const int MULTIPART_THRESHOLD = 30 * self::ONE_MIB;

    // Smallest part size allowed for an S3 multipart upload.
    private const int MINIMUM_PART_SIZE = 5 * self::ONE_MIB;

    // Preferred part size when the file size does not require larger parts.
    private const int DEFAULT_PART_SIZE = 16 * self::ONE_MIB;

    // Maximum number of parts supported by a single S3 multipart upload.
    private const int MAX_PARTS = 10000;

    // Maximum number of multipart upload requests performed simultaneously.
    private const int MAX_CONCURRENCY = 3;

    // Number of multipart parts for which signed URLs are requested at once.
    private const int SIGNING_WINDOW = 10;

    // Maximum number of small files grouped into one upload request.
    private const int MAX_BATCH_FILES = 20;

    // Maximum combined size of the small files in one upload request.
    private const int MAX_BATCH_BYTES = 20 * self::ONE_MIB;

    public function __construct(
        private readonly AvailableNodeNameService $availableNodeNameService,
        private readonly UploadTargetFolderResolver $targetFolderResolver,
    ) {}

    /**
     * Create and cache an upload plan for one browser selection.
     *
     * Planned names are provisional. The storage step checks availability
     * again while holding a lock on the final target directory.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @return array<string, mixed>
     */
    public function makePlan(User $user, array $files, ?int $parentId): array
    {
        $totalBytes = collect($files)->sum(fn (array $file): int => (int) $file['size']);
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

        $rootParent = $this->resolveParent($user, $parentId);
        $reservedNamesByTarget = [];

        $plannedFiles = collect($files)->map(function (array $file) use (
            $user,
            $rootParent,
            &$reservedNamesByTarget,
        ): array {
            $relativePath = $file['relative_path'] ?? null;
            $existingTarget = $this->targetFolderResolver->find(
                user: $user,
                rootParent: $rootParent,
                relativePath: $relativePath,
            );
            $targetKey = $existingTarget
                ? 'folder:'.$existingTarget->id
                : 'path:'.$this->targetFolderResolver->directoryKey($relativePath);
            $reservedNames = $reservedNamesByTarget[$targetKey] ?? [];
            $plannedName = $existingTarget
                ? $this->availableNodeNameService->generate(
                    targetParent: $existingTarget,
                    requestedName: $file['name'],
                    reservedNames: $reservedNames,
                )
                : $this->availableNodeNameService->generateFromUnavailableNames(
                    requestedName: $file['name'],
                    unavailableNames: $reservedNames,
                );

            $reservedNamesByTarget[$targetKey][] = $plannedName;

            return [
                'client_id' => $file['client_id'],
                'original_name' => $file['name'],
                'name' => $plannedName,
                'size' => (int) $file['size'],
                'content_type' => $file['content_type'] ?? null,
                'last_modified' => $file['last_modified'] ?? null,
                'relative_path' => $relativePath,
            ];
        })->values();

        $smallFiles = $plannedFiles
            ->filter(fn (array $file): bool => $file['size'] < self::MULTIPART_THRESHOLD)
            ->values();

        $multipartFiles = $plannedFiles
            ->filter(fn (array $file): bool => $file['size'] >= self::MULTIPART_THRESHOLD)
            ->map(function (array $file): array {
                $partSize = $this->partSizeFor($file['size']);

                return [
                    ...$file,
                    'upload_file_id' => (string) str()->uuid(),
                    'part_size' => $partSize,
                    'part_count' => (int) ceil($file['size'] / $partSize),
                ];
            })
            ->values();

        $plan = [
            'ok' => true,
            'version' => self::VERSION,
            'upload_id' => (string) str()->uuid(),
            'parent_id' => $rootParent->id,
            'threshold_bytes' => self::MULTIPART_THRESHOLD,
            'default_part_size' => self::DEFAULT_PART_SIZE,
            'max_concurrency' => self::MAX_CONCURRENCY,
            'signing_window' => self::SIGNING_WINDOW,
            'files' => $plannedFiles->all(),
            'small_file_batches' => $this->makeSmallFileBatches($smallFiles),
            'multipart_files' => $multipartFiles->all(),
            'errors' => [],
        ];

        Cache::put("upload-plan:{$user->id}:{$plan['upload_id']}", $plan, now()->addHours(2));

        return $plan;
    }

    /**
     * Calculate an S3 multipart part size within the provider limits.
     */
    private function partSizeFor(int $fileSize): int
    {
        $requiredPartSize = (int) ceil($fileSize / self::MAX_PARTS);
        $partSize = max(self::MINIMUM_PART_SIZE, self::DEFAULT_PART_SIZE, $requiredPartSize);

        return (int) ceil($partSize / self::ONE_MIB) * self::ONE_MIB;
    }

    /**
     * Group small planned files into bounded request batches.
     *
     * @param  iterable<int, array<string, mixed>>  $files
     * @return list<array<string, mixed>>
     */
    private function makeSmallFileBatches(iterable $files): array
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

    /**
     * Resolve the user-owned root directory for an upload plan.
     */
    private function resolveParent(User $user, ?int $parentId): File
    {
        if ($parentId !== null) {
            $parent = File::query()
                ->whereKey($parentId)
                ->where('created_by', $user->id)
                ->where('is_folder', true)
                ->whereNull('deleted_at')
                ->firstOrFail();

            abort_unless($parent->isAvailableTreeTarget(), 404);

            return $parent;
        }

        $root = File::query()
            ->where('created_by', $user->id)
            ->whereIsRoot()
            ->whereNull('deleted_at')
            ->firstOrFail();

        abort_unless($root->isAvailableTreeTarget(), 404);

        return $root;
    }
}
