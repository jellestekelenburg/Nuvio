<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitiateMultipartUploadRequest extends FormRequest
{
    /**
     * Determine whether the current user may initiate a planned upload.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules for multipart initiation.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
