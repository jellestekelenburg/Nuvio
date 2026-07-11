<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUploadBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The parent folder where this batch should be stored.
            'parent_id' => ['nullable', 'integer', 'exists:files,id'],

            // The actual small files in this planned batch.
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file'],

            // client_ids must line up with files so the frontend queue can map responses back.
            'client_ids' => ['required', 'array', 'min:1'],
            'client_ids.*' => ['required', 'string'],

            // Used for folder uploads. Keep the same index order as files/client_ids.
            'relative_paths' => ['sometimes', 'array'],
            'relative_paths.*' => ['nullable', 'string'],
        ];
    }
}
