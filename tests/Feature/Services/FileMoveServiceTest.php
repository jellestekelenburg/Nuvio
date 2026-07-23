<?php

namespace Tests\Feature\Services;

use App\Data\FileMoveSelection;
use App\Exceptions\FileMoveException;
use App\Models\File;
use App\Models\User;
use App\Services\FileMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FileMoveServiceTest extends TestCase
{
    use RefreshDatabase;

    private FileMoveService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FileMoveService::class);
    }

    public function test_it_moves_a_file_and_preserves_its_storage_path(): void
    {
        [$user, $root] = $this->userWithRoot();
        $source = $this->createFolder($root, 'Source');
        $target = $this->createFolder($root, 'Target');
        $file = $this->createFile($source, 'report.pdf');
        $originalStoragePath = $file->storage_path;

        $previousUpdater = User::factory()->create();
        $file->updated_by = $previousUpdater->id;
        $file->save();

        $result = $this->service->move(
            user: $user,
            selection: FileMoveSelection::fromIds([$file->id]),
            targetParentId: $target->id,
        );

        $file->refresh();

        $this->assertSame($target->id, $file->parent_id);
        $this->assertSame($originalStoragePath, $file->storage_path);
        $this->assertSame($user->id, $file->updated_by);
        $this->assertSame(1, $result->movedCount);
        $this->assertSame([$source->id], $result->sourceParentIds);
        $this->assertSame($target->id, $result->targetParentId);
        $this->assertSame([], $result->renamedItems);
        $this->assertFalse(File::isBroken());
    }

    public function test_it_moves_a_complete_folder_subtree(): void
    {
        [$user, $root] = $this->userWithRoot();
        $source = $this->createFolder($root, 'Source');
        $target = $this->createFolder($root, 'Target');
        $project = $this->createFolder($source, 'Project');
        $documents = $this->createFolder($project, 'Documents');
        $file = $this->createFile($documents, 'contract.pdf');
        $storagePath = $file->storage_path;

        $result = $this->service->move(
            user: $user,
            selection: FileMoveSelection::fromIds([$project->id]),
            targetParentId: $target->id,
        );

        $project->refresh();
        $documents->refresh();
        $file->refresh();

        $this->assertSame($target->id, $project->parent_id);
        $this->assertSame($project->id, $documents->parent_id);
        $this->assertSame($documents->id, $file->parent_id);
        $this->assertSame($storagePath, $file->storage_path);
        $this->assertSame(1, $result->movedCount);
        $this->assertFalse(File::isBroken());
    }

    public function test_select_all_moves_every_direct_child_except_exclusions(): void
    {
        [$user, $root] = $this->userWithRoot();
        $source = $this->createFolder($root, 'Source');
        $target = $this->createFolder($root, 'Target');
        $first = $this->createFile($source, 'first.txt');
        $excluded = $this->createFile($source, 'excluded.txt');
        $folder = $this->createFolder($source, 'Folder');

        $result = $this->service->move(
            user: $user,
            selection: FileMoveSelection::allFromFolder(
                sourceParentId: $source->id,
                excludedIds: [$excluded->id],
            ),
            targetParentId: $target->id,
        );

        $first->refresh();
        $excluded->refresh();
        $folder->refresh();

        $this->assertSame($target->id, $first->parent_id);
        $this->assertSame($source->id, $excluded->parent_id);
        $this->assertSame($target->id, $folder->parent_id);
        $this->assertSame(2, $result->movedCount);
        $this->assertFalse(File::isBroken());
    }

    public function test_selected_descendants_are_ignored_when_their_ancestor_is_selected(): void
    {
        [$user, $root] = $this->userWithRoot();
        $source = $this->createFolder($root, 'Source');
        $target = $this->createFolder($root, 'Target');
        $folder = $this->createFolder($source, 'Project');
        $child = $this->createFile($folder, 'notes.txt');

        $result = $this->service->move(
            user: $user,
            selection: FileMoveSelection::fromIds([
                $folder->id,
                $child->id,
            ]),
            targetParentId: $target->id,
        );

        $folder->refresh();
        $child->refresh();

        $this->assertSame($target->id, $folder->parent_id);
        $this->assertSame($folder->id, $child->parent_id);
        $this->assertSame(1, $result->movedCount);
        $this->assertFalse(File::isBroken());
    }

    public function test_it_automatically_renames_batch_conflicts(): void
    {
        [$user, $root] = $this->userWithRoot();
        $firstSource = $this->createFolder($root, 'First source');
        $secondSource = $this->createFolder($root, 'Second source');
        $target = $this->createFolder($root, 'Target');

        $first = $this->createFile($firstSource, 'report.pdf');
        $second = $this->createFile($secondSource, 'report.pdf');
        $this->createFile($target, 'report.pdf');

        $result = $this->service->move(
            user: $user,
            selection: FileMoveSelection::fromIds([
                $first->id,
                $second->id,
            ]),
            targetParentId: $target->id,
        );

        $first->refresh();
        $second->refresh();

        $this->assertEqualsCanonicalizing(
            ['report-1.pdf', 'report-2.pdf'],
            [$first->name, $second->name],
        );
        $this->assertSame(2, $result->movedCount);
        $this->assertSame(2, $result->renamedCount());
        $this->assertFalse(File::isBroken());
    }

    public function test_cycle_validation_rolls_back_the_complete_batch(): void
    {
        [$user, $root] = $this->userWithRoot();
        $movableFile = $this->createFile($root, 'movable.txt');
        $folder = $this->createFolder($root, 'Folder');
        $descendantTarget = $this->createFolder(
            $folder,
            'Descendant target',
        );

        try {
            $this->service->move(
                user: $user,
                selection: FileMoveSelection::fromIds([
                    $movableFile->id,
                    $folder->id,
                ]),
                targetParentId: $descendantTarget->id,
            );

            $this->fail('The move should have been rejected.');
        } catch (FileMoveException $exception) {
            $this->assertSame(
                'A folder cannot be moved into itself or one of its descendants.',
                $exception->getMessage(),
            );
        }

        $movableFile->refresh();
        $folder->refresh();

        $this->assertSame($root->id, $movableFile->parent_id);
        $this->assertSame($root->id, $folder->parent_id);
        $this->assertFalse(File::isBroken());
    }

    public function test_moving_to_the_current_parent_is_a_no_op(): void
    {
        [$user, $root] = $this->userWithRoot();
        $folder = $this->createFolder($root, 'Folder');
        $file = $this->createFile($folder, 'notes.txt');

        $previousUpdater = User::factory()->create();
        $file->updated_by = $previousUpdater->id;
        $file->save();

        $result = $this->service->move(
            user: $user,
            selection: FileMoveSelection::fromIds([$file->id]),
            targetParentId: $folder->id,
        );

        $file->refresh();

        $this->assertSame($folder->id, $file->parent_id);
        $this->assertSame($previousUpdater->id, $file->updated_by);
        $this->assertSame(0, $result->movedCount);
        $this->assertSame([], $result->sourceParentIds);
        $this->assertFalse(File::isBroken());
    }

    public function test_the_root_folder_cannot_be_moved(): void
    {
        [$user, $root] = $this->userWithRoot();
        $target = $this->createFolder($root, 'Target');

        $this->expectException(FileMoveException::class);
        $this->expectExceptionMessage(
            'The root folder cannot be moved.',
        );

        $this->service->move(
            user: $user,
            selection: FileMoveSelection::fromIds([$root->id]),
            targetParentId: $target->id,
        );
    }

    public function test_a_foreign_users_item_cannot_be_moved(): void
    {
        [$user, $root] = $this->userWithRoot();
        $target = $this->createFolder($root, 'Target');

        [, $foreignRoot] = $this->userWithRoot();
        $foreignFile = $this->createFile(
            $foreignRoot,
            'private.txt',
        );

        $this->expectException(FileMoveException::class);
        $this->expectExceptionMessage(
            'One or more selected items are no longer available.',
        );

        $this->service->move(
            user: $user,
            selection: FileMoveSelection::fromIds([
                $foreignFile->id,
            ]),
            targetParentId: $target->id,
        );
    }

    public function test_a_foreign_users_folder_cannot_be_used_as_target(): void
    {
        [$user, $root] = $this->userWithRoot();
        $file = $this->createFile($root, 'report.pdf');

        [, $foreignRoot] = $this->userWithRoot();
        $foreignTarget = $this->createFolder(
            $foreignRoot,
            'Private target',
        );

        $this->expectException(FileMoveException::class);
        $this->expectExceptionMessage(
            'The destination folder is no longer available.',
        );

        $this->service->move(
            user: $user,
            selection: FileMoveSelection::fromIds([$file->id]),
            targetParentId: $foreignTarget->id,
        );
    }

    public function test_a_trashed_folder_cannot_be_used_as_target(): void
    {
        [$user, $root] = $this->userWithRoot();
        $file = $this->createFile($root, 'report.pdf');
        $target = $this->createFolder($root, 'Target');

        $target->moveToTrash();

        $this->expectException(FileMoveException::class);
        $this->expectExceptionMessage(
            'The destination folder is no longer available.',
        );

        $this->service->move(
            user: $user,
            selection: FileMoveSelection::fromIds([$file->id]),
            targetParentId: $target->id,
        );
    }

    public function test_an_invalid_exclusion_rejects_the_complete_selection(): void
    {
        [$user, $root] = $this->userWithRoot();
        $source = $this->createFolder($root, 'Source');
        $target = $this->createFolder($root, 'Target');
        $file = $this->createFile($source, 'report.pdf');

        try {
            $this->service->move(
                user: $user,
                selection: FileMoveSelection::allFromFolder(
                    sourceParentId: $source->id,
                    excludedIds: [$target->id],
                ),
                targetParentId: $target->id,
            );

            $this->fail('The move should have been rejected.');
        } catch (FileMoveException $exception) {
            $this->assertSame(
                'One or more selection exclusions are no longer valid.',
                $exception->getMessage(),
            );
        }

        $file->refresh();

        $this->assertSame($source->id, $file->parent_id);
        $this->assertFalse(File::isBroken());
    }

    public function test_one_invalid_id_rejects_the_complete_explicit_batch(): void
    {
        [$user, $root] = $this->userWithRoot();
        $source = $this->createFolder($root, 'Source');
        $target = $this->createFolder($root, 'Target');
        $file = $this->createFile($source, 'report.pdf');

        try {
            $this->service->move(
                user: $user,
                selection: FileMoveSelection::fromIds([
                    $file->id,
                    PHP_INT_MAX,
                ]),
                targetParentId: $target->id,
            );

            $this->fail('The move should have been rejected.');
        } catch (FileMoveException $exception) {
            $this->assertSame(
                'One or more selected items are no longer available.',
                $exception->getMessage(),
            );
        }

        $file->refresh();

        $this->assertSame($source->id, $file->parent_id);
        $this->assertFalse(File::isBroken());
    }

    /**
     * Create a user with a nested-set root folder.
     *
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

    /**
     * Create a stored file directly below the provided parent.
     */
    private function createFile(File $parent, string $name): File
    {
        $file = new File;
        $file->name = $name;
        $file->is_folder = false;
        $file->mime = 'text/plain';
        $file->size = 1;
        $file->storage_path = sprintf(
            'tests/%d/%s',
            $parent->id,
            $name,
        );
        $file->created_by = $parent->created_by;
        $file->updated_by = $parent->created_by;
        $parent->appendNode($file);

        return $file;
    }
}
