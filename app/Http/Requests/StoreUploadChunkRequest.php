<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUploadChunkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // One binary slice of a large file.
            // Later, add max size here that matches UploadPlanService::CHUNK_SIZE.
            'chunk' => ['required', 'file'],
        ];
    }
}
