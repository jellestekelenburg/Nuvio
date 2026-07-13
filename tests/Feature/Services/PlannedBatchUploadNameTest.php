<?php

namespace Tests\Feature\Services;

use App\Models\File;
use App\Models\User;
use App\Services\UploadBatchService;
use App\Services\UploadPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlannedBatchUploadNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_and_batch_storage_allocate_names_in_the_target_directory(): void
    {
        Storage::fake('local');

        $user = User::factory()->create([
            'max_storage' => 1024 * 1024,
            'used_storage' => 0,
        ]);
        $root = $this->createRoot($user);
        $first = UploadedFile::fake()->create('photo.png', 1, 'image/png');
        $second = UploadedFile::fake()->create('photo.png', 1, 'image/png');

        $this->createStoredFile($user, $root, 'photo.png');

        $plan = app(UploadPlanService::class)->makePlan(
            user: $user,
            files: [
                $this->metadata('first', $first),
                $this->metadata('second', $second),
            ],
            parentId: $root->id,
        );

        $this->assertSame(
            ['photo-1.png', 'photo-2.png'],
            collect($plan['files'])->pluck('name')->all(),
        );

        $result = app(UploadBatchService::class)->store(
            user: $user,
            uploadId: $plan['upload_id'],
            batchId: $plan['small_file_batches'][0]['batch_id'],
            files: [$first, $second],
            clientIds: ['first', 'second'],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(
            ['photo-1.png', 'photo-2.png'],
            collect($result['files'])->pluck('name')->all(),
        );
    }

    public function test_planning_scopes_reservations_to_logical_directories_without_creating_them(): void
    {
        $user = User::factory()->create([
            'max_storage' => 1024 * 1024,
            'used_storage' => 0,
        ]);
        $root = $this->createRoot($user);

        $plan = app(UploadPlanService::class)->makePlan(
            user: $user,
            files: [
                $this->metadataArray('photos-first', 'photo.png', 'photos/photo.png'),
                $this->metadataArray('photos-second', 'photo.png', 'photos/photo.png'),
                $this->metadataArray('documents', 'photo.png', 'documents/photo.png'),
            ],
            parentId: $root->id,
        );

        $this->assertSame(
            ['photo.png', 'photo-1.png', 'photo.png'],
            collect($plan['files'])->pluck('name')->all(),
        );
        $this->assertSame(0, $root->children()->count());
    }

    /**
     * Build the browser metadata used by the planning endpoint.
     *
     * @return array<string, mixed>
     */
    private function metadata(string $clientId, UploadedFile $file): array
    {
        return [
            'client_id' => $clientId,
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'relative_path' => null,
            'content_type' => $file->getClientMimeType(),
            'last_modified' => null,
        ];
    }

    /**
     * Build metadata for a planned folder upload.
     *
     * @return array<string, mixed>
     */
    private function metadataArray(
        string $clientId,
        string $name,
        string $relativePath,
    ): array {
        return [
            'client_id' => $clientId,
            'name' => $name,
            'size' => 1,
            'relative_path' => $relativePath,
            'content_type' => 'image/png',
            'last_modified' => null,
        ];
    }

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

    private function createStoredFile(User $user, File $parent, string $name): File
    {
        $file = new File;
        $file->name = $name;
        $file->is_folder = false;
        $file->storage_path = 'tests/'.$name;
        $file->size = 1;
        $file->created_by = $user->id;
        $file->updated_by = $user->id;
        $parent->appendNode($file);

        return $file;
    }
}
