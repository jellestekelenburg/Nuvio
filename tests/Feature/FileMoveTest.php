<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FileMoveTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_cannot_move_files(): void
    {
        $this->patch(route('file.move'), [
            'selection' => [
                'mode' => 'ids',
                'ids' => [1],
            ],
            'target_parent_id' => 2,
        ])->assertRedirect(route('login'));
    }

    public function test_an_unverified_user_cannot_move_files(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->patch(route('file.move'), [
                'selection' => [
                    'mode' => 'ids',
                    'ids' => [1],
                ],
                'target_parent_id' => 2,
            ])
            ->assertRedirect(route('verification.notice'));
    }

    public function test_explicit_ids_can_be_moved_and_the_result_is_shared_with_inertia(): void
    {
        [$user, $root] = $this->userWithRoot();
        $source = $this->createFolder($root, 'Source');
        $target = $this->createFolder($root, 'Target');
        $file = $this->createFile($source, 'report.pdf');
        $sourceUrl = route('myFiles', ['folder' => $source->id]);

        $this->actingAs($user)
            ->from($sourceUrl)
            ->patch(route('file.move'), [
                'selection' => [
                    'mode' => 'ids',
                    'ids' => [$file->id],
                ],
                'target_parent_id' => $target->id,
            ])
            ->assertRedirect($sourceUrl)
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'move_result',
                fn (array $result): bool => $result['moved_count'] === 1
                    && $result['renamed_count'] === 0
                    && $result['source_parent_ids'] === [$source->id]
                    && $result['target_parent_id'] === $target->id,
            );

        $this->assertSame($target->id, $file->fresh()->parent_id);

        $this->get($sourceUrl)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('MyFiles')
                ->where('flash.moveResult.moved_count', 1)
                ->where('flash.moveResult.renamed_count', 0)
                ->where('flash.moveResult.target_parent_id', $target->id));
    }

    /**
     * Invalidate every source and target listing changed by a move.
     */
    public function test_moving_files_invalidates_every_changed_folder_listing(): void
    {
        Cache::flush();

        [$user, $root] = $this->userWithRoot();
        $firstSource = $this->createFolder($root, 'First source');
        $secondSource = $this->createFolder($root, 'Second source');
        $target = $this->createFolder($root, 'Target');
        $unchanged = $this->createFolder($root, 'Unchanged');
        $first = $this->createFile($firstSource, 'first.txt');
        $second = $this->createFile($secondSource, 'second.txt');
        $unchangedFile = $this->createFile($unchanged, 'original.txt');

        $firstSourceUrl = route('myFiles', ['folder' => $firstSource->id]);
        $secondSourceUrl = route('myFiles', ['folder' => $secondSource->id]);
        $targetUrl = route('myFiles', ['folder' => $target->id]);
        $unchangedUrl = route('myFiles', ['folder' => $unchanged->id]);

        $this->actingAs($user);

        $this->getJson($firstSourceUrl)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'first.txt');
        $this->getJson($secondSourceUrl)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'second.txt');
        $this->getJson($targetUrl)
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson($unchangedUrl)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'original.txt');

        $unchangedFile->forceFill([
            'name' => 'changed-in-database.txt',
        ])->save();

        $this->from($firstSourceUrl)
            ->patch(route('file.move'), [
                'selection' => [
                    'mode' => 'ids',
                    'ids' => [$first->id, $second->id],
                ],
                'target_parent_id' => $target->id,
            ])
            ->assertRedirect($firstSourceUrl)
            ->assertSessionHasNoErrors();

        $this->getJson($firstSourceUrl)
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson($secondSourceUrl)
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson($targetUrl)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'first.txt')
            ->assertJsonPath('data.1.name', 'second.txt');
        $this->getJson($unchangedUrl)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'original.txt');
    }

    /**
     * Preserve cached listings when a move does not change the file tree.
     */
    public function test_moving_to_the_current_parent_keeps_the_listing_cache(): void
    {
        Cache::flush();

        [$user, $root] = $this->userWithRoot();
        $folder = $this->createFolder($root, 'Folder');
        $file = $this->createFile($folder, 'original.txt');
        $url = route('myFiles', ['folder' => $folder->id]);

        $this->actingAs($user)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'original.txt');

        $file->forceFill([
            'name' => 'changed-in-database.txt',
        ])->save();

        $this->from($url)
            ->patch(route('file.move'), [
                'selection' => [
                    'mode' => 'ids',
                    'ids' => [$file->id],
                ],
                'target_parent_id' => $folder->id,
            ])
            ->assertRedirect($url)
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'move_result',
                fn (array $result): bool => $result['moved_count'] === 0,
            );

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'original.txt');
    }

    public function test_select_all_moves_unloaded_items_except_explicit_exclusions(): void
    {
        [$user, $root] = $this->userWithRoot();
        $source = $this->createFolder($root, 'Source');
        $target = $this->createFolder($root, 'Target');
        $first = $this->createFile($source, 'first.txt');
        $second = $this->createFile($source, 'second.txt');
        $excluded = $this->createFile($source, 'excluded.txt');

        $this->actingAs($user)
            ->from(route('myFiles', ['folder' => $source->id]))
            ->patch(route('file.move'), [
                'selection' => [
                    'mode' => 'all',
                    'source_parent_id' => $source->id,
                    'excluded_ids' => [$excluded->id],
                ],
                'target_parent_id' => $target->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'move_result',
                fn (array $result): bool => $result['moved_count'] === 2,
            );

        $this->assertSame($target->id, $first->fresh()->parent_id);
        $this->assertSame($target->id, $second->fresh()->parent_id);
        $this->assertSame($source->id, $excluded->fresh()->parent_id);
    }

    public function test_an_unknown_selection_mode_is_rejected(): void
    {
        [$user, $root] = $this->userWithRoot();
        $target = $this->createFolder($root, 'Target');

        $this->actingAs($user)
            ->from(route('myFiles'))
            ->patch(route('file.move'), [
                'selection' => [
                    'mode' => 'unknown',
                ],
                'target_parent_id' => $target->id,
            ])
            ->assertSessionHasErrors('selection.mode');
    }

    public function test_an_explicit_selection_requires_at_least_one_id(): void
    {
        [$user, $root] = $this->userWithRoot();
        $target = $this->createFolder($root, 'Target');

        $this->actingAs($user)
            ->from(route('myFiles'))
            ->patch(route('file.move'), [
                'selection' => [
                    'mode' => 'ids',
                    'ids' => [],
                ],
                'target_parent_id' => $target->id,
            ])
            ->assertSessionHasErrors('selection.ids');
    }

    public function test_foreign_items_are_hidden_by_request_validation(): void
    {
        [$user, $root] = $this->userWithRoot();
        $target = $this->createFolder($root, 'Target');

        [, $foreignRoot] = $this->userWithRoot();
        $foreignFile = $this->createFile($foreignRoot, 'private.txt');

        $this->actingAs($user)
            ->from(route('myFiles'))
            ->patch(route('file.move'), [
                'selection' => [
                    'mode' => 'ids',
                    'ids' => [$foreignFile->id],
                ],
                'target_parent_id' => $target->id,
            ])
            ->assertSessionHasErrors('selection.ids.0');

        $this->assertSame(
            $foreignRoot->id,
            $foreignFile->fresh()->parent_id,
        );
    }

    public function test_a_file_cannot_be_used_as_the_target_folder(): void
    {
        [$user, $root] = $this->userWithRoot();
        $source = $this->createFolder($root, 'Source');
        $file = $this->createFile($source, 'report.txt');
        $invalidTarget = $this->createFile($root, 'target.txt');

        $this->actingAs($user)
            ->from(route('myFiles', ['folder' => $source->id]))
            ->patch(route('file.move'), [
                'selection' => [
                    'mode' => 'ids',
                    'ids' => [$file->id],
                ],
                'target_parent_id' => $invalidTarget->id,
            ])
            ->assertSessionHasErrors('target_parent_id');

        $this->assertSame($source->id, $file->fresh()->parent_id);
    }

    public function test_domain_errors_are_returned_as_move_validation_errors(): void
    {
        [$user, $root] = $this->userWithRoot();
        $folder = $this->createFolder($root, 'Folder');
        $descendant = $this->createFolder($folder, 'Descendant');
        $rootUrl = route('myFiles');

        $this->actingAs($user)
            ->from($rootUrl)
            ->patch(route('file.move'), [
                'selection' => [
                    'mode' => 'ids',
                    'ids' => [$folder->id],
                ],
                'target_parent_id' => $descendant->id,
            ])
            ->assertRedirect($rootUrl)
            ->assertSessionHasErrors([
                'move' => 'A folder cannot be moved into itself or one of its descendants.',
            ]);

        $this->assertSame($root->id, $folder->fresh()->parent_id);
        $this->assertSame($folder->id, $descendant->fresh()->parent_id);
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
