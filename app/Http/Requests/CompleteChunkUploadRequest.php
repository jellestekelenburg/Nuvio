<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteChunkUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The parent folder where the final merged file should be created.
            'parent_id' => ['nullable', 'integer', 'exists:files,id'],
        ];
    }
}
