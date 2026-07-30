<?php

namespace App\Services;

use App\Exceptions\CorruptFileTreeException;
use App\Models\File;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

final class FileTreeMutationService
{
    /**
     * Serialize structural file-tree mutations for one user.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(int $userId, Closure $callback): mixed
    {
        return DB::transaction(function () use ($userId, $callback): mixed {
            User::query()
                ->whereKey($userId)
                ->lockForUpdate()
                ->firstOrFail(['id']);

            return $callback();
        }, 5);
    }

    public function ensureHealthy(int $userId): void
    {
        if (File::scoped(['created_by' => $userId])->isBroken()) {
            throw CorruptFileTreeException::forUser($userId);
        }
    }
}
