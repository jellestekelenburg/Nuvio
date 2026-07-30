<?php

namespace App\Http\Controllers;

use App\Http\Requests\BrowseFoldersRequest;
use App\Http\Resources\FolderPickerResource;
use App\Models\File;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class FolderBrowserController extends Controller
{
    /**
     * Return the current folder, its ancestors and paginated subfolders.
     */
    public function __invoke(
        BrowseFoldersRequest $request,
    ): AnonymousResourceCollection {
        $user = $this->authenticatedUser($request);

        $hasFolderChildren = static fn (Builder $query) => $query
            ->where('created_by', $user->id)
            ->where('is_folder', true)
            ->whereNull('deleted_at');

        $currentFolder = File::query()
            ->withExists([
                'children as has_children' => $hasFolderChildren,
            ])
            ->whereKey($request->parentId())
            ->where('created_by', $user->id)
            ->where('is_folder', true)
            ->whereNull('deleted_at')
            ->firstOrFail();

        abort_unless($currentFolder->isAvailableTreeTarget(), 404);

        $ancestors = File::query()
            ->withExists([
                'children as has_children' => $hasFolderChildren,
            ])
            ->where('created_by', $user->id)
            ->where('_lft', '<', $currentFolder->getLft())
            ->where('_rgt', '>', $currentFolder->getRgt())
            ->whereNull('deleted_at')
            ->orderBy('_lft')
            ->get();

        $folders = File::query()
            ->withExists([
                'children as has_children' => $hasFolderChildren,
            ])
            ->where('created_by', $user->id)
            ->where('parent_id', $currentFolder->id)
            ->where('is_folder', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return FolderPickerResource::collection($folders)
            ->additional([
                'current' => (new FolderPickerResource(
                    $currentFolder,
                ))->resolve($request),
                'ancestors' => FolderPickerResource::collection(
                    $ancestors,
                )->resolve($request),
            ]);
    }
}
