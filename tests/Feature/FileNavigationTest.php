<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

    /**
     * Reuse a cached folder listing for an identical controller request.
     */
    public function test_an_identical_folder_listing_request_uses_the_cache(): void
    {
        Cache::flush();

        [$user, $root] = $this->userWithRoot();
        $file = $this->child($root, 'Original name.txt');
        $url = route('myFiles', ['folder' => $root->id]);

        $this->actingAs($user)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Original name.txt');

        File::query()
            ->whereKey($file->id)
            ->update(['name' => 'Changed in database.txt']);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Original name.txt');

        $this->getJson($url.'?sortBy=name&sortDirection=asc')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Changed in database.txt');
    }

    /**
     * Invalidate the parent listing cache after creating a folder.
     */
    public function test_creating_a_folder_invalidates_the_parent_listing_cache(): void
    {
        Cache::flush();

        [$user, $root] = $this->userWithRoot();
        $url = route('myFiles', ['folder' => $root->id]);

        $this->actingAs($user)
            ->getJson($url)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->from($url)
            ->post(route('folder.create'), [
                'name' => 'Projects',
                'parent_id' => $root->id,
            ])
            ->assertRedirect($url);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Projects');
    }

    /**
     * Invalidate the target listing cache after uploading one file.
     */
    public function test_uploading_a_file_invalidates_the_target_folder_listing_cache(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::fake('local');
        Cache::flush();

        [$user, $root] = $this->userWithRoot();
        $url = route('myFiles', ['folder' => $root->id]);

        $this->actingAs($user)
            ->getJson($url)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->post(route('file.store'), [
            'files' => [
                UploadedFile::fake()->create('report.txt', 1, 'text/plain'),
            ],
            'parent_id' => $root->id,
        ])->assertOk();

        $this->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'report.txt')
            ->assertJsonPath('data.0.is_folder', false);
    }

    /**
     * Invalidate the target listing cache after uploading a folder tree.
     */
    public function test_uploading_a_folder_tree_invalidates_the_target_folder_listing_cache(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::fake('local');
        Cache::flush();

        [$user, $root] = $this->userWithRoot();
        $url = route('myFiles', ['folder' => $root->id]);

        $this->actingAs($user)
            ->getJson($url)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->post(route('file.store'), [
            'files' => [
                UploadedFile::fake()->create('report.txt', 1, 'text/plain'),
            ],
            'relative_paths' => [
                'Portfolio/Documents/report.txt',
            ],
            'parent_id' => $root->id,
        ])->assertOk();

        $this->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Portfolio')
            ->assertJsonPath('data.0.is_folder', true);
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

    /**
     * Invalidate every affected source listing after moving items to trash.
     */
    public function test_moving_items_to_trash_invalidates_every_source_listing(): void
    {
        Cache::flush();

        [$user, $root] = $this->userWithRoot();
        $photos = $this->child($root, 'Photos', true);
        $documents = $this->child($root, 'Documents', true);
        $unchanged = $this->child($root, 'Unchanged', true);
        $photo = $this->child($photos, 'photo.jpeg');
        $document = $this->child($documents, 'report.pdf');
        $unchangedFile = $this->child($unchanged, 'original.txt');

        $photosUrl = route('myFiles', ['folder' => $photos->id]);
        $documentsUrl = route('myFiles', ['folder' => $documents->id]);
        $unchangedUrl = route('myFiles', ['folder' => $unchanged->id]);

        $this->actingAs($user);

        $this->getJson($photosUrl)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'photo.jpeg');
        $this->getJson($documentsUrl)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'report.pdf');
        $this->getJson($unchangedUrl)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'original.txt');

        $unchangedFile->forceFill([
            'name' => 'changed-in-database.txt',
        ])->save();

        $this->delete(route('file.delete'), [
            'all' => false,
            'ids' => [$photo->id, $document->id],
            'parent_id' => $photos->id,
        ])->assertRedirect($photosUrl);

        $this->getJson($photosUrl)
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson($documentsUrl)
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson($unchangedUrl)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'original.txt');
    }

    /**
     * Invalidate every destination listing after restoring trashed items.
     */
    public function test_restoring_items_invalidates_every_destination_listing(): void
    {
        Cache::flush();

        [$user, $root] = $this->userWithRoot();
        $photos = $this->child($root, 'Photos', true);
        $documents = $this->child($root, 'Documents', true);
        $unchanged = $this->child($root, 'Unchanged', true);
        $photo = $this->child($photos, 'photo.jpeg');
        $document = $this->child($documents, 'report.pdf');
        $unchangedFile = $this->child($unchanged, 'original.txt');

        $photo->moveToTrash();
        $document->moveToTrash();

        $photosUrl = route('myFiles', ['folder' => $photos->id]);
        $documentsUrl = route('myFiles', ['folder' => $documents->id]);
        $unchangedUrl = route('myFiles', ['folder' => $unchanged->id]);

        $this->actingAs($user);

        $this->getJson($photosUrl)
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson($documentsUrl)
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson($unchangedUrl)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'original.txt');

        $unchangedFile->forceFill([
            'name' => 'changed-in-database.txt',
        ])->save();

        $this->post(route('file.restore'), [
            'all' => false,
            'ids' => [$photo->id, $document->id],
        ])->assertRedirect(route('trash'));

        $this->getJson($photosUrl)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'photo.jpeg');
        $this->getJson($documentsUrl)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'report.pdf');
        $this->getJson($unchangedUrl)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'original.txt');
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
