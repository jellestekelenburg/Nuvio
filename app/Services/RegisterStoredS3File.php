<?php

namespace App\Services;

use App\Models\File;
use App\Models\User;

class RegisterStoredS3File
{
    public function handle(
        User $user,
        File $parent,
        string $s3Key,
        string $name,
        ?string $mime,
        int $size,
    ): File {
        $model = new File;
        $model->is_folder = false;
        $model->storage_path = $s3Key;
        $model->name = $name;
        $model->mime = $mime;
        $model->size = $size;
        $model->created_by = $user->id;
        $model->updated_by = $user->id;

        $parent->appendNode($model);

        return $model;
    }
}
