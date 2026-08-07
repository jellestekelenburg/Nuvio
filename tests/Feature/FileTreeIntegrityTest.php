<?php

namespace Tests\Feature;

use App\Jobs\DeletePendingFiles;
use App\Models\File;
use App\Models\User;
use App\Services\FileTreeMutationService;
use App\Services\StorageUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileTreeIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_user_has_an_isolated_nested_set(): void
    {
        [$firstUser, $firstRoot] = $this->userWithRoot();
        [$secondUser, $secondRoot] = $this->userWithRoot();

        $this->createFile($firstRoot, 'first.txt');

        $firstRoot->refresh();
        $secondRoot->refresh();

        $this->assertSame(1, $firstRoot->getLft());
        $this->assertSame(4, $firstRoot->getRgt());
        $this->assertSame(1, $secondRoot->getLft());
        $this->assertSame(2, $secondRoot->getRgt());
        $this->assertFalse(
            File::scoped([
                'created_by' => $firstUser->id,
            ])->isBroken(),
        );
        $this->assertFalse(
            File::scoped([
                'created_by' => $secondUser->id,
            ])->isBroken(),
        );
        $this->assertFalse(File::isBroken());
    }

    public function test_permanent_delete_removes_the_complete_scoped_subtree(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::fake('local');

        [$user, $root] = $this->userWithRoot();
        $folder = $this->createFolder($root, 'Folder');
        $file = $this->createFile($folder, 'report.txt');

        Storage::put($file->storage_path, 'contents');

        $folder->moveToTrash();
        $folder->permanently_delete = true;
        $folder->save();

        $this->runDeleteJob($user);

        Storage::assertMissing($file->storage_path);
        $this->assertDatabaseMissing('files', ['id' => $folder->id]);
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        $this->assertDatabaseHas('files', ['id' => $root->id]);
        $this->assertFalse(File::isBroken());
    }

    public function test_permanent_delete_stops_before_storage_when_tree_is_corrupt(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::fake('local');

        [$user, $root] = $this->userWithRoot();
        $folder = $this->createFolder($root, 'Folder');
        $file = $this->createFile($folder, 'report.txt');

        Storage::put($file->storage_path, 'contents');

        $folder->moveToTrash();
        $folder->permanently_delete = true;
        $folder->save();

        DB::table('files')
            ->where('id', $file->id)
            ->update([
                '_lft' => $folder->getLft(),
                '_rgt' => $folder->getRgt(),
            ]);

        $this->assertTrue(File::isBroken());

        $this->runDeleteJob($user);

        Storage::assertExists($file->storage_path);
        $this->assertDatabaseHas('files', ['id' => $folder->id]);
        $this->assertDatabaseHas('files', ['id' => $file->id]);
    }

    public function test_active_descendant_of_a_trashed_folder_is_not_available(): void
    {
        [, $root] = $this->userWithRoot();
        $folder = $this->createFolder($root, 'Folder');
        $child = $this->createFolder($folder, 'Child');

        $folder->moveToTrash();

        $this->assertFalse($child->isAvailableTreeTarget());
    }

    private function runDeleteJob(User $user): void
    {
        (new DeletePendingFiles($user->id))->handle(
            app(StorageUserService::class),
            app(FileTreeMutationService::class),
        );
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

    private function createFile(File $parent, string $name): File
    {
        $file = new File;
        $file->name = $name;
        $file->is_folder = false;
        $file->mime = 'text/plain';
        $file->size = 8;
        $file->storage_path = "tests/{$parent->created_by}/{$name}";
        $file->created_by = $parent->created_by;
        $file->updated_by = $parent->created_by;
        $parent->appendNode($file);

        return $file;
    }
}
