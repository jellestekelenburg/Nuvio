<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class InitiateMultipartUploadRequest extends ParentIdBaseRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return parent::rules();
    }
}
