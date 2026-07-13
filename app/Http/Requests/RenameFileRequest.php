<?php

namespace App\Http\Requests;

use App\Models\File;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RenameFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $file = $this->route('file');

        return $file instanceof File
            && ! $file->trashed()
            && ! $file->isRoot()
            && $file->isOwnedBy($this->user()?->id);
    }

    public function rules(): array
    {
        /** @var File $file */
        $file = $this->route('file');

        return [
            'name' => [
                'bail',
                'required',
                'string',
                'max:255',
                'not_in:.,..',
                'regex:/^[^\\\\\/]+$/u',
                Rule::unique(File::class, 'name')
                    ->where('created_by', $this->user()?->id)
                    ->where('parent_id', $file->parent_id)
                    ->whereNull('deleted_at')
                    ->ignore($file->id),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A file or folder with this name already exists here.',
            'name.regex' => 'The name cannot contain a slash.',
            'name.not_in' => 'Please choose another name.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var File $file */
            $file = $this->route('file');

            if (! $file->is_folder
                && $this->extensionOf((string) $this->input('name')) !== $this->extensionOf($file->name)
            ) {
                $validator->errors()->add('name', 'The file extension cannot be changed.');
            }
        }];
    }

    private function extensionOf(string $name): string
    {
        $lastDot = strrpos($name, '.');

        if ($lastDot === false || str_starts_with($name, '.') || $lastDot === strlen($name) - 1) {
            return '';
        }

        return substr($name, $lastDot);
    }
}
