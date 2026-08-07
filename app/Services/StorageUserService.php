<?php

namespace App\Services;

use App\Events\StorageUsageUpdated;
use App\Models\File;
use App\Models\User;
use App\Support\SizeFormatter;
use Illuminate\Support\Facades\Cache;

/**
 * @phpstan-type StorageStats array{
 *     used_bytes: int,
 *     max_bytes: int,
 *     used_formatted: string,
 *     max_formatted: string,
 *     is_full: bool,
 *     percentage: int|float
 * }
 */
class StorageUserService
{
    private function cacheKey(User $user): string
    {
        return "storage:stats:{$user->id}";
    }

    public function clearCache(User $user): void
    {
        Cache::forget($this->cacheKey($user));
    }

    /**
     * @return StorageStats
     */
    private function makeStats(int $used, int $max): array
    {
        return [
            'used_bytes' => $used,
            'max_bytes' => $max,
            'used_formatted' => SizeFormatter::formatBytes($used),
            'max_formatted' => SizeFormatter::formatBytes($max, 0),
            'is_full' => $max > 0 && $used >= $max,
            'percentage' => $max > 0 ? round(($used / $max) * 100, 2) : 0,
        ];
    }

    /**
     * @return StorageStats
     */
    public function getCachedOrRecalculate(User $user): array
    {
        $key = $this->cacheKey($user);
        $cached = Cache::get($key);

        if (
            is_array($cached)
            && isset($cached['used_bytes'], $cached['max_bytes'])
            && is_int($cached['used_bytes'])
            && is_int($cached['max_bytes'])
        ) {
            return $this->makeStats($cached['used_bytes'], $cached['max_bytes']);
        }

        return $this->recalculate($user);
    }

    public function addUsage(User $user, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $user->increment('used_storage', $bytes);
        $user->refresh();

        $stats = $this->makeStats((int) $user->used_storage, (int) $user->max_storage);

        Cache::forget($this->cacheKey($user));
        Cache::put($this->cacheKey($user), $stats, now()->addMinutes(10));

        $this->broadcast($user, $stats);
    }

    /**
     * @return StorageStats
     */
    public function recalculate(User $user): array
    {
        $used = (int) File::query()->withoutGlobalScopes()
            ->where('created_by', $user->id)
            ->where('is_folder', false)
            ->sum('size');

        User::where('id', $user->id)->update(['used_storage' => $used]);

        $max = (int) $user->max_storage;
        $stats = $this->makeStats($used, $max);

        Cache::put($this->cacheKey($user), $stats, now()->addMinutes(10));
        $this->broadcast($user, $stats);

        return $stats;
    }

    /**
     * @param  StorageStats  $stats
     */
    private function broadcast(User $user, array $stats): void
    {
        StorageUsageUpdated::dispatch(
            $user->id,
            $stats,
        );
    }
}
