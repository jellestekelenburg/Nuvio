<?php

namespace App\Services;

use App\Models\File;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class UploadBatchService
{
    public function __construct(
        private readonly StoreUploadedFile $storeUploadedFile,
        private readonly StorageUserService $storageUserService,
        private readonly UploadTargetFolderResolver $targetFolderResolver,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, string>  $clientIds
     * @param  array<int, string|null>  $relativePaths
     */
    public function store(
        User $user,
        string $uploadId,
        string $batchId,
        array $files,
        ?int $parentId,
        array $clientIds,
        array $relativePaths,
    ): array {

        if (count($files) !== count($clientIds)) {
            return [
                'ok' => false,
                'message' => 'Files and client_ids do not match.',
            ];
        }

        $totalBytes = collect($files)->sum(fn ($file) => (int) $file->getSize());
        $remainingBytes = max(0, $user->getMaxStorageSize() - $user->getUsedStorageSize());

        if ($totalBytes > $remainingBytes) {
            return [
                'ok' => false,
                'code' => 'storage_limit_exceeded',
                'message' => 'You do not have enough storage for this upload to continue.',
            ];
        }

        $parent = $this->resolveParent($user, $parentId);

        $uploaded = [];
        $uploadedBytes = 0;

        foreach ($files as $index => $file) {
            $clientId = $clientIds[$index];
            $relativePath = $relativePaths[$index] ?? null;

            $targetParent = $this->targetFolderResolver->resolve(
                user: $user,
                rootParent: $parent,
                relativePath: $relativePath,
            );

            $model = $this->storeUploadedFile->handle(
                file: $file,
                user: $user,
                parent: $targetParent,
            );

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

    private function resolveParent(User $user, ?int $parentId)
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
}
