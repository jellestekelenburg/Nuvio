<?php

namespace Tests\Feature\Services;

use App\Models\File;
use App\Models\MultipartUpload;
use App\Models\User;
use App\Services\S3MultipartUploadService;
use App\Services\UploadMultipartService;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;
use Tests\TestCase;

class MultipartUploadCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Invalidate cached listings after committing a multipart upload.
     */
    public function test_completing_a_multipart_upload_invalidates_cached_file_listings(): void
    {
        Cache::flush();

        [$user, $root] = $this->userWithRoot();
        $upload = $this->createMultipartUpload(
            user: $user,
            root: $root,
            relativePath: 'Portfolio/video.mp4',
        );
        $url = route('myFiles', ['folder' => $root->id]);

        $this->actingAs($user)
            ->getJson($url)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->bindS3Service(objectSize: $upload->size);

        $result = app(UploadMultipartService::class)->complete(
            user: $user,
            uploadId: $upload->upload_id,
            uploadFileId: $upload->upload_file_id,
            parts: [[
                'part_number' => 1,
                'etag' => 'test-etag',
            ]],
        );

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['ok']);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Portfolio')
            ->assertJsonPath('data.0.is_folder', true);
    }

    /**
     * Retry cache invalidation for an already completed multipart upload.
     */
    public function test_retrying_a_completed_multipart_upload_invalidates_cached_file_listings(): void
    {
        Cache::flush();

        [$user, $root] = $this->userWithRoot();
        $file = $this->createStoredFile($user, $root, 'original.txt');
        $upload = $this->createMultipartUpload(
            user: $user,
            root: $root,
            completedFile: $file,
        );
        $url = route('myFiles', ['folder' => $root->id]);

        $this->actingAs($user)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'original.txt');

        $file->forceFill(['name' => 'changed-in-database.txt'])->save();

        $this->bindS3Service(objectSize: $upload->size);

        $result = app(UploadMultipartService::class)->complete(
            user: $user,
            uploadId: $upload->upload_id,
            uploadFileId: $upload->upload_file_id,
            parts: [],
        );

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['ok']);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'changed-in-database.txt');
    }

    /**
     * Bind an S3 multipart service backed by deterministic SDK responses.
     */
    private function bindS3Service(int $objectSize): void
    {
        $handler = new MockHandler;
        $handler->append(
            new Result,
            new Result(['ContentLength' => $objectSize]),
        );

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'eu-west-1',
            'credentials' => [
                'key' => 'test-key',
                'secret' => 'test-secret',
            ],
            'handler' => $handler,
        ]);

        $reflection = new ReflectionClass(S3MultipartUploadService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('client')->setValue($service, $client);
        $reflection->getProperty('bucket')->setValue($service, 'test-bucket');

        $this->app->instance(S3MultipartUploadService::class, $service);
    }

    /**
     * @return array{0: User, 1: File}
     */
    private function userWithRoot(): array
    {
        $user = User::factory()->create([
            'max_storage' => 1024 * 1024,
            'used_storage' => 0,
        ]);

        $root = new File;
        $root->name = $user->email;
        $root->is_folder = true;
        $root->created_by = $user->id;
        $root->updated_by = $user->id;
        $root->makeRoot()->save();

        return [$user, $root];
    }

    /**
     * Create one active or completed multipart upload record.
     */
    private function createMultipartUpload(
        User $user,
        File $root,
        ?string $relativePath = null,
        ?File $completedFile = null,
    ): MultipartUpload {
        return MultipartUpload::query()->create([
            'upload_id' => (string) str()->uuid(),
            'upload_file_id' => (string) str()->uuid(),
            'user_id' => $user->id,
            'parent_id' => $root->id,
            'client_id' => 'video',
            'name' => 'video.mp4',
            'relative_path' => $relativePath,
            'content_type' => 'video/mp4',
            'size' => 1024,
            'part_size' => 1024,
            'part_count' => 1,
            'reserved_bytes' => $completedFile ? 0 : 1024,
            's3_key' => 'files/test/video.mp4',
            's3_upload_id' => 'test-s3-upload',
            'status' => $completedFile
                ? MultipartUpload::STATUS_COMPLETED
                : MultipartUpload::STATUS_INITIATED,
            'completed_file_id' => $completedFile?->id,
            'initiated_at' => now(),
            'completed_at' => $completedFile ? now() : null,
        ]);
    }

    /**
     * Create one registered file below the provided parent.
     */
    private function createStoredFile(User $user, File $parent, string $name): File
    {
        $file = new File;
        $file->name = $name;
        $file->is_folder = false;
        $file->storage_path = 'files/test/'.$name;
        $file->mime = 'text/plain';
        $file->size = 1024;
        $file->created_by = $user->id;
        $file->updated_by = $user->id;
        $parent->appendNode($file);

        return $file;
    }
}
