<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreUploadBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The actual small files in this planned batch.
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file'],

            // client_ids must line up with files so the frontend queue can map responses back.
            'client_ids' => ['required', 'array', 'min:1'],
            'client_ids.*' => ['required', 'string', 'max:64', 'distinct'],
        ];
    }
}
