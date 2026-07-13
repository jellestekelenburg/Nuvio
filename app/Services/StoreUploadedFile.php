<?php

namespace App\Services;

use App\Models\File;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class StoreUploadedFile
{
    /**
     * Store an uploaded file and register it below the target directory.
     */
    public function handle(
        UploadedFile $file,
        User $user,
        File $parent,
        string $name,
    ): File {
        $path = $file->store('/files'.$user->id);

        $model = new File;
        $model->is_folder = false;
        $model->storage_path = $path;
        $model->name = $name;
        $model->mime = $file->getClientMimeType();
        $model->size = $file->getSize();
        $model->created_by = $user->id;
        $model->updated_by = $user->id;

        $parent->appendNode($model);

        return $model;
    }
}
