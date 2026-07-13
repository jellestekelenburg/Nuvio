<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FileRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_file_can_be_renamed_without_changing_its_extension(): void
    {
        [$user, $root] = $this->userWithRoot();
        $file = $this->child($root, 'old-name.png');

        $this->actingAs($user)
            ->patch(route('file.rename', $file), ['name' => 'new-name.png'])
            ->assertSessionHasNoErrors();

        $this->assertSame('new-name.png', $file->fresh()->name);
    }

    public function test_a_file_extension_cannot_be_changed(): void
    {
        [$user, $root] = $this->userWithRoot();
        $file = $this->child($root, 'report.sql');

        $this->actingAs($user)
            ->from('/my-files')
            ->patch(route('file.rename', $file), ['name' => 'report.png'])
            ->assertSessionHasErrors('name');

        $this->assertSame('report.sql', $file->fresh()->name);
    }

    public function test_a_dotfile_name_is_fully_editable(): void
    {
        [$user, $root] = $this->userWithRoot();
        $file = $this->child($root, '.env');

        $this->actingAs($user)
            ->patch(route('file.rename', $file), ['name' => '.env.local'])
            ->assertSessionHasNoErrors();

        $this->assertSame('.env.local', $file->fresh()->name);
    }

    public function test_renaming_a_folder_updates_descendant_paths(): void
    {
        [$user, $root] = $this->userWithRoot();
        $folder = $this->child($root, 'Old Folder', true);
        $nested = $this->child($folder, 'Nested Folder', true);
        $file = $this->child($nested, 'notes.txt');

        $this->actingAs($user)
            ->patch(route('file.rename', $folder), ['name' => 'New Folder'])
            ->assertSessionHasNoErrors();

        $this->assertSame('new-folder', $folder->fresh()->path);
        $this->assertSame('new-folder/nested-folder', $nested->fresh()->path);
        $this->assertSame('new-folder/nested-folder/notestxt', $file->fresh()->path);
    }

    private function userWithRoot(): array
    {
        $user = User::factory()->create();
        $root = new File;
        $root->name = $user->email;
        $root->is_folder = true;
        $root->created_by = $user->id;
        $root->updated_by = $user->id;
        $root->makeRoot()->save();

        return [$user, $root];
    }

    private function child(File $parent, string $name, bool $isFolder = false): File
    {
        $file = new File;
        $file->name = $name;
        $file->is_folder = $isFolder;
        $file->created_by = $parent->created_by;
        $file->updated_by = $parent->created_by;
        $parent->appendNode($file);

        return $file;
    }
}
