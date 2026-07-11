<?php

namespace App\Http\Requests;

use App\Models\File;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

class StoreFileRequest extends ParentIdBaseRequest
{
    protected function prepareForValidation(): void
    {
        $paths = array_filter($this->relative_paths ?? [], fn ($f) => $f != null);

        $this->merge([
            'file_paths' => $paths,
            'folder_name' => $this->detectFolderName($paths),
        ]);
    }

    protected function passedValidation(): void
    {
        $data = $this->validated();

        $this->replace([
            'file_tree' => $this->buildFileTree($this->file_paths, $data['files']),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $parentId = $this->parent?->id
            ?? File::query()
                ->where('created_by', Auth::id())
                ->whereIsRoot()
                ->value('id');

        return array_merge(parent::rules(), [
            'files' => [
                'required',
                'array',
                'min:1',
            ],
            'files.*' => [
                'required',
                'file',
                function ($attribute, $value, $fail) use ($parentId) {
                    if (! $this->folder_name) {
                        /* @var $value UploadedFile */
                        $file = File::query()
                            ->where('name', $value->getClientOriginalName())
                            ->where('files.created_by', auth()->id())
                            ->where('parent_id', $parentId)
                            ->whereNull('deleted_at')->exists();

                        if ($file) {
                            $fail('File "'.$value->getClientOriginalName().'" already exists.');
                        }
                    }
                },
            ],
            'folder_name' => [
                'nullable',
                'string',
                function ($attribute, $value, $fail) use ($parentId) {
                    if ($value) {
                        /* @var $value UploadedFile */
                        $file = File::query()
                            ->where('name', $value)
                            ->where('files.created_by', auth()->id())
                            ->where('parent_id', $parentId)
                            ->whereNull('deleted_at')->exists();

                        if ($file) {
                            $fail('Folder "'.$value.'" already exists.');
                        }
                    }
                },
            ],
        ]);
    }

    public function detectFolderName(array $paths): ?string
    {
        if (! $paths) {
            return null;
        }

        $parts = explode('/', $paths[0]);

        return $parts[0];
    }

    private function buildFileTree(array $filePaths, $files)
    {
        $filePaths = array_slice($filePaths, 0, count($files));
        $filePaths = array_filter($filePaths, fn ($f) => $f != null);

        $tree = [];

        foreach ($filePaths as $ind => $filePath) {
            $parts = explode('/', $filePath);

            $currentNode = &$tree;
            foreach ($parts as $i => $part) {
                if (! isset($currentNode[$part])) {
                    $currentNode[$part] = [];
                }

                if ($i == count($parts) - 1) {
                    $currentNode[$part] = $files[$ind];
                } else {
                    $currentNode = &$currentNode[$part];
                }
            }
        }

        return $tree;
    }
}
