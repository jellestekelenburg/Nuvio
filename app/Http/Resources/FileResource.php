<?php

namespace App\Http\Resources;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

class FileResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $file = $this->file();

        return [
            'id' => $file->id,
            'name' => $file->name,
            'path' => $file->path,
            'parent_id' => $file->parent_id,
            'is_folder' => $file->is_folder,
            'mime' => $file->mime,
            'size' => $file->getFileSize(),
            'owner' => $file->owner,
            'type' => $this->getFileType(),
            'created_at' => $file->created_at?->diffForHumans(),
            'updated_at' => $file->updated_at?->diffForHumans(),
            'created_by' => $file->created_by,
            'updated_by' => $file->updated_by,
            'deleted_at' => $file->deleted_at,
        ];
    }

    private function getFileType(): string
    {
        $path = $this->file()->storage_path;

        return $path === null ? '' : pathinfo($path, PATHINFO_EXTENSION);
    }

    private function file(): File
    {
        if (! $this->resource instanceof File) {
            throw new LogicException('FileResource requires an App\Models\File instance.');
        }

        return $this->resource;
    }
}
