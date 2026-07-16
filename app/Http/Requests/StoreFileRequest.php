<?php

namespace App\Http\Requests;

use App\Models\File;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

class StoreFileRequest extends ParentIdBaseRequest
{
    protected function prepareForValidation(): void
    {
        $relativePaths = $this->input('relative_paths', []);
        $paths = [];

        if (is_array($relativePaths)) {
            foreach ($relativePaths as $relativePath) {
                if (is_string($relativePath) && $relativePath !== '') {
                    $paths[] = $relativePath;
                }
            }
        }

        $this->merge([
            'file_paths' => $paths,
            'folder_name' => $this->detectFolderName($paths),
        ]);
    }

    protected function passedValidation(): void
    {
        $data = $this->validated();
        $filePaths = $this->input('file_paths', []);
        $files = $data['files'] ?? [];
        $uploadedFiles = [];

        if (is_array($files)) {
            foreach ($files as $file) {
                if ($file instanceof UploadedFile) {
                    $uploadedFiles[] = $file;
                }
            }
        }

        $this->replace(['file_tree' => $this->buildFileTree(
            is_array($filePaths) ? array_values(array_filter($filePaths, 'is_string')) : [],
            $uploadedFiles,
        )]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $parentId = $this->parent instanceof File
            ? $this->parent->id
            : File::query()
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
                function (string $attribute, mixed $value, Closure $fail) use ($parentId): void {
                    if (! $this->folder_name) {
                        if (! $value instanceof UploadedFile) {
                            return;
                        }

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
                function (string $attribute, mixed $value, Closure $fail) use ($parentId): void {
                    if (is_string($value) && $value !== '') {
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

    /**
     * @param  list<string>  $paths
     */
    public function detectFolderName(array $paths): ?string
    {
        if (! $paths) {
            return null;
        }

        $parts = explode('/', $paths[0]);

        return $parts[0];
    }

    /**
     * @param  list<string>  $filePaths
     * @param  list<UploadedFile>  $files
     * @return array<string, mixed>
     */
    private function buildFileTree(array $filePaths, array $files): array
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
