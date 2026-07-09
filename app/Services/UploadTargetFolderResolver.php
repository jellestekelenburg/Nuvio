<?php

namespace App\Services;

use App\Models\File;
use App\Models\User;

class UploadTargetFolderResolver
{
    public function resolve(
        User $user,
        File $rootParent,
        ?string $relativePath,
    ): File {
        if (! $relativePath) {
            return $rootParent;
        }

        $parts = array_values(array_filter(explode('/', trim($relativePath, '/'))));

        array_pop($parts);

        if (! $parts) {
            return $rootParent;
        }

        $parent = $rootParent;

        foreach ($parts as $folderName) {
            $folder = File::query()
                ->where('created_by', $user->id)
                ->where('parent_id', $parent->id)
                ->where('is_folder', true)
                ->where('name', $folderName)
                ->whereNull('deleted_at')
                ->first();

            if (! $folder) {
                $folder = new File;
                $folder->is_folder = true;
                $folder->name = $folderName;

                $parent->appendNode($folder);
            }

            $parent = $folder;
        }

        return $parent;
    }
}
