<?php

namespace App\Services;

use App\Models\File;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * @phpstan-type ListingParameters array{
 *     page: int,
 *     limit: int,
 *     sort_by: string,
 *     sort_direction: string
 * }
 * @phpstan-type ListingPayload array<string, mixed>
 */
final class FileListCache
{
    private const int TTL_SECONDS = 6 * 60 * 60;

    private const string KEY_VERSION = 'v1';

    /**
     * Retrieve a folder listing from cache or execute and cache its resolver.
     *
     * The parameters must already be normalized so the cache key always
     * represents the database query that will actually be executed.
     *
     * @param  ListingParameters  $parameters
     * @param  Closure(): ListingPayload  $resolver
     * @return ListingPayload
     */
    public function rememberListing(
        User $user,
        File $folder,
        array $parameters,
        Closure $resolver,
    ): array {
        /** @var ListingPayload $listing */
        $listing = Cache::tags(
            $this->listingTags($user, $folder),
        )->remember(
            $this->listingKey($user, $folder, $parameters),
            self::TTL_SECONDS,
            $resolver,
        );

        return $listing;
    }

    /**
     * Invalidate every cached listing variant for one folder.
     */
    public function flushFolder(User $user, File|int $folder): void
    {
        Cache::tags([
            $this->folderTag($user, $folder),
        ])->flush();
    }

    /**
     * Invalidate every cached file listing owned by one user.
     */
    public function flushUser(User $user): void
    {
        Cache::tags([
            $this->userTag($user),
        ])->flush();
    }

    /**
     * Build the tags shared by a cached folder listing.
     *
     * @return list<string>
     */
    private function listingTags(User $user, File $folder): array
    {
        return [
            $this->userTag($user),
            $this->folderTag($user, $folder),
        ];
    }

    /**
     * Build the tag shared by all the file listings owned by one user.
     */
    private function userTag(User $user): string
    {
        return sprintf(
            'files:user:%d',
            (int) $user->getKey(),
        );
    }

    /**
     * Build the tag shared by all the listing variants for one folder.
     */
    private function folderTag(User $user, File|int $folder): string
    {
        return sprintf(
            'files:user:%d:folder:%d',
            (int) $user->getKey(),
            $this->folderId($folder),
        );
    }

    /**
     * Build a deterministic key for one exact listing request.
     *
     * @param  ListingParameters  $parameters
     */
    private function listingKey(
        User $user,
        File $folder,
        array $parameters,
    ): string {
        return sprintf(
            'file-list:%s:user:%d:folder:%d:page:%d:limit:%d:sort:%s:%s',
            self::KEY_VERSION,
            (int) $user->getKey(),
            (int) $folder->getKey(),
            $parameters['page'],
            $parameters['limit'],
            $parameters['sort_by'],
            $parameters['sort_direction'],
        );
    }

    /**
     * Resolve a folder model or identifier to its integer identifier.
     */
    private function folderId(File|int $folder): int
    {
        return $folder instanceof File
            ? (int) $folder->getKey()
            : $folder;
    }
}
