<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CompleteMultipartUploadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'parts' => ['required', 'array', 'min:1', 'max:10000'],
            'parts.*.part_number' => ['required', 'integer', 'distinct', 'min:1'],
            'parts.*.etag' => ['required', 'string', 'max:255'],
        ];
    }
}
