<?php

namespace Tests\Feature\Services;

use App\Models\File;
use App\Models\User;
use App\Services\AvailableNodeNameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailableNodeNameServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private File $root;

    private AvailableNodeNameService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->root = $this->createRoot($this->user);
        $this->service = app(AvailableNodeNameService::class);
    }

    public function test_it_returns_the_requested_name_when_available(): void
    {
        $this->assertSame('photo.png', $this->service->generate(
            targetParent: $this->root,
            requestedName: 'photo.png',
        ));
    }

    public function test_it_uses_the_first_available_numeric_suffix(): void
    {
        $this->createFile($this->root, 'photo.png');
        $this->createFile($this->root, 'photo-1.png');
        $this->createFile($this->root, 'photo-3.png');

        $this->assertSame('photo-2.png', $this->service->generate(
            targetParent: $this->root,
            requestedName: 'photo.png',
        ));
    }

    public function test_names_are_scoped_to_the_target_directory(): void
    {
        $photos = $this->createFolder($this->root, 'photos');
        $this->createFile($this->root, 'photo.png');

        $this->assertSame('photo.png', $this->service->generate(
            targetParent: $photos,
            requestedName: 'photo.png',
        ));
    }

    public function test_it_considers_reserved_names_and_dotfiles(): void
    {
        $this->assertSame('.env-2', $this->service->generateFromUnavailableNames(
            requestedName: '.env',
            unavailableNames: ['.env', '.env-1'],
        ));
    }

    public function test_it_can_ignore_the_current_node_for_future_renames(): void
    {
        $file = $this->createFile($this->root, 'photo.png');

        $this->assertSame('photo.png', $this->service->generate(
            targetParent: $this->root,
            requestedName: 'photo.png',
            ignoreNodeId: $file->id,
        ));
    }

    public function test_it_escapes_like_wildcards_in_file_names(): void
    {
        $this->createFile($this->root, 'report%_!.txt');
        $this->createFile($this->root, 'report%_!-1.txt');

        $this->assertSame('report%_!-2.txt', $this->service->generate(
            targetParent: $this->root,
            requestedName: 'report%_!.txt',
        ));
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

    private function createFile(File $parent, string $name): File
    {
        $file = new File;
        $file->name = $name;
        $file->is_folder = false;
        $file->storage_path = 'tests/'.$name;
        $file->size = 1;
        $file->created_by = $this->user->id;
        $file->updated_by = $this->user->id;
        $parent->appendNode($file);

        return $file;
    }

    private function createFolder(File $parent, string $name): File
    {
        $folder = new File;
        $folder->name = $name;
        $folder->is_folder = true;
        $folder->created_by = $this->user->id;
        $folder->updated_by = $this->user->id;
        $parent->appendNode($folder);

        return $folder;
    }
}
