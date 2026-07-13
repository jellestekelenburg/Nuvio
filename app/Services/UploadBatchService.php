<?php

namespace App\Services;

use App\Models\File;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UploadBatchService
{
    public function __construct(
        private readonly StoreUploadedFile $storeUploadedFile,
        private readonly StorageUserService $storageUserService,
        private readonly UploadTargetFolderResolver $targetFolderResolver,
        private readonly AvailableNodeNameService $availableNodeNameService,
    ) {}

    /**
     * Store one planned small-file batch.
     *
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, string>  $clientIds
     */
    public function store(
        User $user,
        string $uploadId,
        string $batchId,
        array $files,
        array $clientIds,
    ): array {
        $plan = Cache::get("upload-plan:{$user->id}:{$uploadId}");

        if (! $plan) {
            return [
                'ok' => false,
                'code' => 'upload_plan_not_found',
                'message' => 'Upload plan not found or expired.',
            ];
        }

        if (($plan['version'] ?? null) !== UploadPlanService::VERSION) {
            return [
                'ok' => false,
                'code' => 'upload_plan_incompatible',
                'message' => 'Upload plan is incompatible. Please start the upload again.',
            ];
        }

        $plannedBatch = collect($plan['small_file_batches'] ?? [])
            ->firstWhere('batch_id', $batchId);

        if (! $plannedBatch) {
            return [
                'ok' => false,
                'code' => 'upload_batch_not_found',
                'message' => 'Batch was not found in this upload plan.',
            ];
        }

        $expectedClientIds = array_values($plannedBatch['files'] ?? []);

        if (
            count($files) !== count($clientIds) ||
            $expectedClientIds !== array_values($clientIds)
        ) {
            return [
                'ok' => false,
                'code' => 'upload_batch_mismatch',
                'message' => 'Uploaded files do not match the planned batch.',
            ];
        }

        $plannedFiles = collect($plan['files'] ?? [])->keyBy('client_id');

        foreach ($files as $index => $file) {
            $plannedFile = $plannedFiles->get($clientIds[$index]);

            if (
                ! $plannedFile ||
                (int) $file->getSize() !== (int) $plannedFile['size']
            ) {
                return [
                    'ok' => false,
                    'code' => 'upload_file_mismatch',
                    'message' => 'An uploaded file does not match its plan.',
                ];
            }
        }

        $totalBytes = collect($files)
            ->sum(fn (UploadedFile $file): int => (int) $file->getSize());

        $user->refresh();
        $remainingBytes = max(
            0,
            $user->getMaxStorageSize() - $user->getUsedStorageSize(),
        );

        if ($totalBytes > $remainingBytes) {
            return [
                'ok' => false,
                'code' => 'storage_limit_exceeded',
                'message' => 'You do not have enough storage for this upload to continue.',
            ];
        }

        $rootParent = $this->resolvePlannedParent(
            user: $user,
            parentId: (int) $plan['parent_id'],
        );
        $uploaded = [];
        $uploadedBytes = 0;

        foreach ($files as $index => $file) {
            $clientId = $clientIds[$index];
            $plannedFile = $plannedFiles->get($clientId);

            $model = DB::transaction(function () use (
                $user,
                $file,
                $rootParent,
                $plannedFile,
            ): File {
                // Locking the plan root also serializes missing folder creation.
                $lockedRootParent = File::query()
                    ->whereKey($rootParent->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $targetParent = $this->targetFolderResolver->resolve(
                    user: $user,
                    rootParent: $lockedRootParent,
                    relativePath: $plannedFile['relative_path'] ?? null,
                );
                $lockedTargetParent = File::query()
                    ->whereKey($targetParent->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $finalName = $this->availableNodeNameService->generate(
                    targetParent: $lockedTargetParent,
                    requestedName: $plannedFile['original_name'],
                );

                return $this->storeUploadedFile->handle(
                    file: $file,
                    user: $user,
                    parent: $lockedTargetParent,
                    name: $finalName,
                );
            });

            $uploadedBytes += (int) $model->size;
            $uploaded[] = [
                'client_id' => $clientId,
                'file_id' => $model->id,
                'name' => $model->name,
                'size' => $model->size,
                'status' => 'done',
            ];
        }

        $this->storageUserService->addUsage($user, $uploadedBytes);

        return [
            'ok' => true,
            'upload_id' => $uploadId,
            'batch_id' => $batchId,
            'files' => $uploaded,
        ];
    }

    /**
     * Resolve and authorize the root directory stored in the upload plan.
     */
    private function resolvePlannedParent(User $user, int $parentId): File
    {
        return File::query()
            ->whereKey($parentId)
            ->where('created_by', $user->id)
            ->where('is_folder', true)
            ->whereNull('deleted_at')
            ->firstOrFail();
    }
}
