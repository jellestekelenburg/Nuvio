<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FolderBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_cannot_browse_folders(): void
    {
        $this->getJson(route('api.folders.index', [
            'parent_id' => 1,
        ]))->assertUnauthorized();
    }

    public function test_it_returns_the_current_folder_ancestors_and_direct_subfolders(): void
    {
        [$user, $root] = $this->userWithRoot();
        $projects = $this->createFolder($root, 'Projects');
        $year = $this->createFolder($projects, '2026');
        $archive = $this->createFolder($year, 'Archive');
        $nested = $this->createFolder($archive, 'Nested');
        $this->createFile($year, 'notes.txt');

        $response = $this->actingAs($user)->getJson(
            route('api.folders.index', [
                'parent_id' => $year->id,
            ]),
        );

        $response
            ->assertOk()
            ->assertJsonPath('current.id', $year->id)
            ->assertJsonPath('current.parent_id', $projects->id)
            ->assertJsonPath('current.is_root', false)
            ->assertJsonPath('current.has_children', true)
            ->assertJsonPath('ancestors.0.id', $root->id)
            ->assertJsonPath('ancestors.1.id', $projects->id)
            ->assertJsonCount(2, 'ancestors')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $archive->id)
            ->assertJsonPath('data.0.name', 'Archive')
            ->assertJsonPath('data.0.has_children', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissing(['id' => $nested->id])
            ->assertJsonMissing(['name' => 'notes.txt']);
    }

    public function test_root_is_returned_explicitly_and_has_no_ancestors(): void
    {
        [$user, $root] = $this->userWithRoot();

        $this->actingAs($user)
            ->getJson(route('api.folders.index', [
                'parent_id' => $root->id,
            ]))
            ->assertOk()
            ->assertJsonPath('current.id', $root->id)
            ->assertJsonPath('current.parent_id', null)
            ->assertJsonPath('current.is_root', true)
            ->assertJsonCount(0, 'ancestors');
    }

    public function test_direct_folders_are_paginated_in_name_order(): void
    {
        [$user, $root] = $this->userWithRoot();
        $this->createFolder($root, 'Charlie');
        $this->createFolder($root, 'Alpha');
        $this->createFolder($root, 'Bravo');
        $this->createFile($root, 'ignored.txt');

        $firstPage = $this->actingAs($user)->getJson(
            route('api.folders.index', [
                'parent_id' => $root->id,
                'per_page' => 2,
                'page' => 1,
            ]),
        );

        $firstPage
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Alpha')
            ->assertJsonPath('data.1.name', 'Bravo')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);

        $this->actingAs($user)
            ->getJson(route('api.folders.index', [
                'parent_id' => $root->id,
                'per_page' => 2,
                'page' => 2,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Charlie')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_foreign_and_trashed_folders_cannot_be_browsed(): void
    {
        [$user] = $this->userWithRoot();
        [, $foreignRoot] = $this->userWithRoot();

        $this->actingAs($user)
            ->getJson(route('api.folders.index', [
                'parent_id' => $foreignRoot->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        [$owner, $ownerRoot] = $this->userWithRoot();
        $trashed = $this->createFolder($ownerRoot, 'Trashed');
        $trashed->moveToTrash();

        $this->actingAs($owner)
            ->getJson(route('api.folders.index', [
                'parent_id' => $trashed->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_foreign_and_trashed_children_are_not_exposed(): void
    {
        [$user, $root] = $this->userWithRoot();
        $visible = $this->createFolder($root, 'Visible');
        $trashed = $this->createFolder($root, 'Trashed');
        $trashed->moveToTrash();

        $foreignUser = User::factory()->create();
        $foreign = new File;
        $foreign->name = 'Foreign';
        $foreign->is_folder = true;
        $foreign->created_by = $foreignUser->id;
        $foreign->updated_by = $foreignUser->id;
        $foreign->makeRoot()->save();

        // Simulate an invalid cross-owner parent reference without asking
        // NestedSet to violate its owner scope.
        DB::table('files')
            ->where('id', $foreign->id)
            ->update(['parent_id' => $root->id]);

        $this->actingAs($user)
            ->getJson(route('api.folders.index', [
                'parent_id' => $root->id,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonMissing(['id' => $trashed->id])
            ->assertJsonMissing(['id' => $foreign->id]);
    }

    public function test_parent_and_pagination_parameters_are_validated(): void
    {
        [$user, $root] = $this->userWithRoot();

        $this->actingAs($user)
            ->getJson(route('api.folders.index'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->actingAs($user)
            ->getJson(route('api.folders.index', [
                'parent_id' => $root->id,
                'page' => 0,
                'per_page' => 101,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page', 'per_page']);
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
