<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class UploadPlanRequest extends ParentIdBaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // The frontend only sends metadata here, not the real files.
            // The real File objects stay in the browser until the batch/chunk step.
            'files' => ['required', 'array', 'min:1', 'max:1000'],
            'files.*.client_id' => ['required', 'string', 'max:64', 'distinct'],
            'files.*.name' => ['required', 'string', 'max:1024'],
            'files.*.size' => ['required', 'integer', 'min:0'],
            'files.*.relative_path' => ['nullable', 'string', 'max:4096'],
            'files.*.content_type' => ['nullable', 'string', 'max:255'],
            'files.*.last_modified' => ['nullable', 'integer'],
        ]);
    }
}
