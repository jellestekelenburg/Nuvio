<?php

namespace App\Services;

use App\Models\File;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class StoreUploadedFile
{
    public function handle(UploadedFile $file, User $user, File $parent): File
    {
        $path = $file->store('/files'.$user->id);

        $model = new File;
        $model->is_folder = false;
        $model->storage_path = $path;
        $model->name = $file->getClientOriginalName();
        $model->mime = $file->getClientMimeType();
        $model->size = $file->getSize();

        $parent->appendNode($model);

        return $model;
    }
}
