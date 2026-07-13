<?php

namespace App\Services;

use App\Models\File;
use App\Models\User;

class UploadTargetFolderResolver
{
    /**
     * Resolve the target folder and create missing directory nodes.
     */
    public function resolve(
        User $user,
        File $rootParent,
        ?string $relativePath,
    ): File {
        $parts = $this->directoryParts($relativePath);

        if (! $parts) {
            return $rootParent;
        }

        $parent = $rootParent;

        foreach ($parts as $folderName) {
            $folder = $this->findChildFolder($user, $parent, $folderName);

            if (! $folder) {
                $folder = new File;
                $folder->is_folder = true;
                $folder->name = $folderName;
                $folder->created_by = $user->id;
                $folder->updated_by = $user->id;

                $parent->appendNode($folder);
            }

            $parent = $folder;
        }

        return $parent;
    }

    /**
     * Find the target folder without creating missing directory nodes.
     */
    public function find(
        User $user,
        File $rootParent,
        ?string $relativePath,
    ): ?File {
        $parts = $this->directoryParts($relativePath);

        if (! $parts) {
            return $rootParent;
        }

        $parent = $rootParent;

        foreach ($parts as $folderName) {
            $folder = $this->findChildFolder($user, $parent, $folderName);

            if (! $folder) {
                return null;
            }

            $parent = $folder;
        }

        return $parent;
    }

    /**
     * Build a stable key for the target directory in an upload selection.
     */
    public function directoryKey(?string $relativePath): string
    {
        return implode('/', $this->directoryParts($relativePath));
    }

    /**
     * Extract directory segments from a browser relative file path.
     *
     * @return array<int, string>
     */
    private function directoryParts(?string $relativePath): array
    {
        if (! $relativePath) {
            return [];
        }

        $parts = array_values(array_filter(
            explode('/', trim(str_replace('\\', '/', $relativePath), '/')),
            static fn (string $part): bool => $part !== '',
        ));

        array_pop($parts);

        return $parts;
    }

    /**
     * Find an active child folder owned by the uploading user.
     */
    private function findChildFolder(User $user, File $parent, string $name): ?File
    {
        return File::query()
            ->where('created_by', $user->id)
            ->where('parent_id', $parent->id)
            ->where('is_folder', true)
            ->where('name', $name)
            ->whereNull('deleted_at')
            ->first();
    }
}
