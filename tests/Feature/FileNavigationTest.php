<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FileNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_navigate_a_deep_folder_hierarchy_by_stable_id(): void
    {
        [$user, $root] = $this->userWithRoot();
        $photos = $this->child($root, 'Photos', true);
        $year = $this->child($photos, '2026', true);
        $image = $this->child($year, 'IMG-4810.jpeg');

        $this->actingAs($user)
            ->get(route('myFiles', ['folder' => $year->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MyFiles')
                ->where('folder.id', $year->id)
                ->where('folder.parent_id', $photos->id)
                ->where('folder.name', '2026')
                ->has('ancestors.data', 3)
                ->where('ancestors.data.0.id', $root->id)
                ->where('ancestors.data.1.id', $photos->id)
                ->where('ancestors.data.2.id', $year->id)
                ->has('files.data', 1)
                ->where('files.data.0.id', $image->id)
                ->where('files.data.0.name', 'IMG-4810.jpeg')
                ->missing('folder.path')
                ->missing('files.data.0.path'));
    }

    public function test_folder_navigation_rejects_paths_files_and_foreign_folders(): void
    {
        [$user, $root] = $this->userWithRoot();
        $folder = $this->child($root, 'Photos', true);
        $file = $this->child($folder, 'photo.jpeg');
        [, $otherRoot] = $this->userWithRoot();
        $otherFolder = $this->child($otherRoot, 'Private', true);

        $this->actingAs($user);

        $this->get('/my-files/photos')->assertNotFound();
        $this->get(route('myFiles', ['folder' => $file->id]))->assertNotFound();
        $this->get(route('myFiles', ['folder' => $otherFolder->id]))->assertNotFound();
    }

    public function test_a_folder_url_stays_stable_when_the_folder_is_renamed(): void
    {
        [$user, $root] = $this->userWithRoot();
        $folder = $this->child($root, 'Old name', true);
        $url = route('myFiles', ['folder' => $folder->id]);

        $this->actingAs($user)
            ->from($url)
            ->patch(route('file.rename', $folder), ['name' => 'New name'])
            ->assertRedirect($url);

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('folder.id', $folder->id)
                ->where('folder.name', 'New name'));
    }

    public function test_deleting_from_a_folder_redirects_back_to_its_id_route(): void
    {
        [$user, $root] = $this->userWithRoot();
        $folder = $this->child($root, 'Photos', true);
        $file = $this->child($folder, 'photo.jpeg');

        $this->actingAs($user)
            ->delete(route('file.delete'), [
                'all' => false,
                'ids' => [$file->id],
                'parent_id' => $folder->id,
            ])
            ->assertRedirect(route('myFiles', ['folder' => $folder->id]));
    }

    public function test_the_files_table_no_longer_stores_a_derived_path(): void
    {
        $this->assertFalse(Schema::hasColumn('files', 'path'));
        $this->assertTrue(Schema::hasColumn('files', 'parent_id'));
        $this->assertTrue(Schema::hasColumn('files', 'storage_path'));
    }

    /**
     * @return array{0: User, 1: File}
     */
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
