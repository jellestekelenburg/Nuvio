<?php

namespace App\Jobs;

use App\Exceptions\CorruptFileTreeException;
use App\Models\File;
use App\Models\User;
use App\Services\FileTreeMutationService;
use App\Services\StorageUserService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DeletePendingFiles implements ShouldQueue
{
    use Queueable;

    public int $tries = 50;

    public int $timeout = 300;

    public function __construct(
        private readonly int $userId,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                "permanent-file-deletion:{$this->userId}",
            ))
                ->shared()
                ->releaseAfter(10)
                ->expireAfter(330),
        ];
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * Execute the job.
     */
    public function handle(
        StorageUserService $storageUserService,
        FileTreeMutationService $fileTreeMutationService,
    ): void {
        try {
            while ($batch = $this->nextPendingBatch(
                $fileTreeMutationService,
            )) {
                $this->deleteFilesFromStorage($batch['files']);
                $this->deleteRecordsFromDatabase(
                    $batch['root'],
                    $fileTreeMutationService,
                );
            }
        } catch (CorruptFileTreeException $exception) {
            $this->fail($exception);

            return;
        }

        $this->cleanup(
            $storageUserService,
            $fileTreeMutationService,
        );
    }

    /**
     * @return array{root: File, files: Collection<int, File>}|null
     */
    private function nextPendingBatch(
        FileTreeMutationService $fileTreeMutationService,
    ): ?array {
        return $fileTreeMutationService->run(
            $this->userId,
            function () use ($fileTreeMutationService): ?array {
                $fileTreeMutationService->ensureHealthy($this->userId);

                $root = File::onlyTrashed()
                    ->where('created_by', $this->userId)
                    ->where('permanently_delete', true)
                    ->orderBy('_lft')
                    ->first();

                if (! $root instanceof File) {
                    return null;
                }

                return [
                    'root' => $root,
                    'files' => $this->getSubtree($root),
                ];
            },
        );
    }

    /**
     * Include soft-deleted descendants as well.
     *
     * @return Collection<int, File>
     */
    private function getSubtree(File $root): Collection
    {
        return File::withTrashed()
            ->where('created_by', $root->created_by)
            ->where(
                $root->getLftName(),
                '>=',
                $root->getLft(),
            )
            ->where(
                $root->getRgtName(),
                '<=',
                $root->getRgt(),
            )
            ->orderBy($root->getLftName())
            ->get();
    }

    /**
     * Delete storage objects before removing their database records.
     *
     * @param  Collection<int, File>  $files
     */
    private function deleteFilesFromStorage(Collection $files): void
    {
        foreach ($files as $file) {
            if ($file->is_folder || $file->storage_path === null) {
                continue;
            }

            if (! Storage::delete($file->storage_path)) {
                throw new RuntimeException(
                    "Could not delete storage object: {$file->storage_path}",
                );
            }
        }
    }

    /**
     * Delete all records from the database.
     */
    private function deleteRecordsFromDatabase(
        File $root,
        FileTreeMutationService $fileTreeMutationService,
    ): void {
        $fileTreeMutationService->run(
            $this->userId,
            function () use (
                $root,
                $fileTreeMutationService,
            ): void {
                $fileTreeMutationService->ensureHealthy($this->userId);

                $freshRoot = File::onlyTrashed()
                    ->where('created_by', $this->userId)
                    ->whereKey($root->id)
                    ->where('permanently_delete', true)
                    ->lockForUpdate()
                    ->first();

                if (! $freshRoot) {
                    return;
                }

                $freshRoot->forceDelete();
            },
        );
    }

    /**
     * Cleanup all caches and recalculate userStorage
     */
    private function cleanup(
        StorageUserService $storageUserService,
        FileTreeMutationService $fileTreeMutationService,
    ): void {
        $fileTreeMutationService->run(
            $this->userId,
            function () use ($storageUserService): void {
                $user = User::query()->find($this->userId);

                if (! $user) {
                    return;
                }

                $storageUserService->recalculate($user);
            },
        );
    }
}
