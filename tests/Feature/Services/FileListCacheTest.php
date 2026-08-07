<?php

namespace Tests\Feature\Services;

use App\Models\File;
use App\Models\User;
use App\Services\FileListCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FileListCacheTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private File $root;

    private FileListCache $fileListCache;

    /**
     * Prepare an isolated user, root folder, and cache service.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->user = User::factory()->create();
        $this->root = $this->createRoot($this->user);
        $this->fileListCache = app(FileListCache::class);
    }

    /**
     * Reuse the cached payload for an identical folder listing request.
     */
    public function test_it_remembers_an_identical_folder_listing(): void
    {
        $resolverCalls = 0;

        $parameters = $this->listingParameters();

        $first = $this->fileListCache->rememberListing(
            user: $this->user,
            folder: $this->root,
            parameters: $parameters,
            resolver: function () use (&$resolverCalls): array {
                $resolverCalls++;

                return [
                    'data' => [
                        ['id' => 10, 'name' => 'example.txt'],
                    ],
                    'links' => [
                        'next' => null,
                    ],
                    'meta' => [
                        'total' => 1,
                    ],
                ];
            },
        );

        $second = $this->fileListCache->rememberListing(
            user: $this->user,
            folder: $this->root,
            parameters: $parameters,
            resolver: function () use (&$resolverCalls): array {
                $resolverCalls++;

                return [
                    'data' => [
                        ['id' => 20, 'name' => 'should-not-be-returned.txt'],
                    ],
                    'links' => [
                        'next' => null,
                    ],
                    'meta' => [
                        'total' => 1,
                    ],
                ];
            },
        );

        $this->assertSame($first, $second);
        $this->assertSame(1, $resolverCalls);
    }

    /**
     * Store every listing parameter variant as a separate cache entry.
     *
     * @param  array<string, int|string>  $changes
     */
    #[DataProvider('listingKeyVariants')]
    public function test_listing_parameters_use_different_cache_entries(array $changes): void
    {
        $resolverCalls = 0;

        $originalParameters = $this->listingParameters();

        $changedParameters = array_replace(
            $originalParameters,
            $changes,
        );

        $original = $this->fileListCache->rememberListing(
            user: $this->user,
            folder: $this->root,
            parameters: $originalParameters,
            resolver: function () use (&$resolverCalls): array {
                $resolverCalls++;

                return [
                    'variant' => 'original',
                ];
            },
        );

        $changed = $this->fileListCache->rememberListing(
            user: $this->user,
            folder: $this->root,
            parameters: $changedParameters,
            resolver: function () use (&$resolverCalls): array {
                $resolverCalls++;

                return [
                    'variant' => 'changed',
                ];
            },
        );

        $originalAgain = $this->fileListCache->rememberListing(
            user: $this->user,
            folder: $this->root,
            parameters: $originalParameters,
            resolver: function () use (&$resolverCalls): array {
                $resolverCalls++;

                return [
                    'variant' => 'should-not-be-returned',
                ];
            },
        );

        $this->assertNotSame($original, $changed);
        $this->assertSame($original, $originalAgain);
        $this->assertSame(2, $resolverCalls);
    }

    /**
     * Recalculate a listing after its cache entry reaches the configured TTL.
     */
    public function test_it_recalculates_a_listing_after_the_ttl_expires(): void
    {
        $resolverCalls = 0;
        $parameters = $this->listingParameters();
        $ttlSeconds = 6 * 60 * 60;

        $first = $this->fileListCache->rememberListing(
            user: $this->user,
            folder: $this->root,
            parameters: $parameters,
            resolver: function () use (&$resolverCalls): array {
                $resolverCalls++;

                return ['version' => $resolverCalls];
            },
        );

        $this->travel($ttlSeconds - 1)->seconds();

        $beforeExpiration = $this->fileListCache->rememberListing(
            user: $this->user,
            folder: $this->root,
            parameters: $parameters,
            resolver: function () use (&$resolverCalls): array {
                $resolverCalls++;

                return ['version' => $resolverCalls];
            },
        );

        $this->travel(2)->seconds();

        $afterExpiration = $this->fileListCache->rememberListing(
            user: $this->user,
            folder: $this->root,
            parameters: $parameters,
            resolver: function () use (&$resolverCalls): array {
                $resolverCalls++;

                return ['version' => $resolverCalls];
            },
        );

        $this->assertSame(1, $first['version']);
        $this->assertSame(1, $beforeExpiration['version']);
        $this->assertSame(2, $afterExpiration['version']);
        $this->assertSame(2, $resolverCalls);
    }

    /**
     * Invalidate one folder without clearing other folders for the same user.
     */
    public function test_it_flushes_only_the_selected_folder(): void
    {
        $photos = $this->createFolder($this->root, 'Photos');
        $documents = $this->createFolder($this->root, 'Documents');

        $photosResolverCalls = 0;
        $documentsResolverCalls = 0;
        $parameters = $this->listingParameters();

        $this->fileListCache->rememberListing(
            user: $this->user,
            folder: $photos,
            parameters: $parameters,
            resolver: function () use (&$photosResolverCalls): array {
                $photosResolverCalls++;

                return [
                    'folder' => 'photos',
                    'version' => $photosResolverCalls,
                ];
            },
        );

        $this->fileListCache->rememberListing(
            user: $this->user,
            folder: $documents,
            parameters: $parameters,
            resolver: function () use (&$documentsResolverCalls): array {
                $documentsResolverCalls++;

                return [
                    'folder' => 'documents',
                    'version' => $documentsResolverCalls,
                ];
            },
        );

        $this->fileListCache->flushFolder($this->user, $photos);

        $photosAfterFlush = $this->fileListCache->rememberListing(
            user: $this->user,
            folder: $photos,
            parameters: $parameters,
            resolver: function () use (&$photosResolverCalls): array {
                $photosResolverCalls++;

                return [
                    'folder' => 'photos',
                    'version' => $photosResolverCalls,
                ];
            },
        );

        $documentsAfterFlush = $this->fileListCache->rememberListing(
            user: $this->user,
            folder: $documents,
            parameters: $parameters,
            resolver: function () use (&$documentsResolverCalls): array {
                $documentsResolverCalls++;

                return [
                    'folder' => 'documents',
                    'version' => $documentsResolverCalls,
                ];
            },
        );

        $this->assertSame(2, $photosResolverCalls);
        $this->assertSame(1, $documentsResolverCalls);
        $this->assertSame(2, $photosAfterFlush['version']);
        $this->assertSame(1, $documentsAfterFlush['version']);
    }

    /**
     * Invalidate every folder for one user without clearing another user's cache.
     */
    public function test_it_flushes_only_the_selected_user(): void
    {
        $photos = $this->createFolder($this->root, 'Photos');
        $otherUser = User::factory()->create();
        $otherRoot = $this->createRoot($otherUser);

        $rootResolverCalls = 0;
        $photosResolverCalls = 0;
        $otherUserResolverCalls = 0;
        $parameters = $this->listingParameters();

        $this->fileListCache->rememberListing(
            user: $this->user,
            folder: $this->root,
            parameters: $parameters,
            resolver: function () use (&$rootResolverCalls): array {
                $rootResolverCalls++;

                return ['version' => $rootResolverCalls];
            },
        );

        $this->fileListCache->rememberListing(
            user: $this->user,
            folder: $photos,
            parameters: $parameters,
            resolver: function () use (&$photosResolverCalls): array {
                $photosResolverCalls++;

                return ['version' => $photosResolverCalls];
            },
        );

        $this->fileListCache->rememberListing(
            user: $otherUser,
            folder: $otherRoot,
            parameters: $parameters,
            resolver: function () use (&$otherUserResolverCalls): array {
                $otherUserResolverCalls++;

                return ['version' => $otherUserResolverCalls];
            },
        );

        $this->fileListCache->flushUser($this->user);

        $rootAfterFlush = $this->fileListCache->rememberListing(
            user: $this->user,
            folder: $this->root,
            parameters: $parameters,
            resolver: function () use (&$rootResolverCalls): array {
                $rootResolverCalls++;

                return ['version' => $rootResolverCalls];
            },
        );

        $photosAfterFlush = $this->fileListCache->rememberListing(
            user: $this->user,
            folder: $photos,
            parameters: $parameters,
            resolver: function () use (&$photosResolverCalls): array {
                $photosResolverCalls++;

                return ['version' => $photosResolverCalls];
            },
        );

        $otherUserAfterFlush = $this->fileListCache->rememberListing(
            user: $otherUser,
            folder: $otherRoot,
            parameters: $parameters,
            resolver: function () use (&$otherUserResolverCalls): array {
                $otherUserResolverCalls++;

                return ['version' => $otherUserResolverCalls];
            },
        );

        $this->assertSame(2, $rootResolverCalls);
        $this->assertSame(2, $photosResolverCalls);
        $this->assertSame(1, $otherUserResolverCalls);
        $this->assertSame(2, $rootAfterFlush['version']);
        $this->assertSame(2, $photosAfterFlush['version']);
        $this->assertSame(1, $otherUserAfterFlush['version']);
    }

    /**
     * Provide normalized parameters for one listing request.
     *
     * @return array{
     *     page: int,
     *     limit: int,
     *     sort_by: string,
     *     sort_direction: string
     * }
     */
    private function listingParameters(): array
    {
        return [
            'page' => 1,
            'limit' => 50,
            'sort_by' => 'size',
            'sort_direction' => 'desc',
        ];
    }

    /**
     * Provide every query parameter that must produce a distinct cache key.
     *
     * @return array<string, array{array<string, int|string>}>
     */
    public static function listingKeyVariants(): array
    {
        return [
            'page' => [['page' => 2]],
            'limit' => [['limit' => 25]],
            'sort column' => [['sort_by' => 'name']],
            'sort direction' => [['sort_direction' => 'asc']],
        ];
    }

    /**
     * Create a nested-set root folder owned by the provided user.
     */
    private function createRoot(User $user): File
    {
        $root = new File;
        $root->name = $user->email;
        $root->is_folder = true;
        $root->created_by = $user->id;
        $root->updated_by = $user->id;
        $root->makeRoot()->save();

        return $root;
    }

    /**
     * Create a folder directly below the provided parent.
     */
    private function createFolder(File $parent, string $name): File
    {
        $folder = new File;
        $folder->name = $name;
        $folder->is_folder = true;
        $folder->created_by = $parent->created_by;
        $folder->updated_by = $parent->created_by;
        $parent->appendNode($folder);

        return $folder;
    }
}
