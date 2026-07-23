<?php

namespace App\Http\Resources;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class FolderPickerResource extends JsonResource
{
    /**
     * Transform a folder into picker-specific data.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     parent_id: int|null,
     *     is_root: bool,
     *     has_children: bool
     * }
     */
    public function toArray(Request $request): array
    {
        $folder = $this->folder();

        return [
            'id' => (int) $folder->id,
            'name' => $folder->name,
            'parent_id' => $folder->parent_id,
            'is_root' => $folder->isRoot(),
            'has_children' => (bool) $folder->getAttribute(
                'has_children',
            ),
        ];
    }

    /**
     * Return the underlying folder model.
     */
    private function folder(): File
    {
        if (! $this->resource instanceof File) {
            throw new LogicException(
                'FolderPickerResource requires an App\Models\File instance.',
            );
        }

        return $this->resource;
    }
}
