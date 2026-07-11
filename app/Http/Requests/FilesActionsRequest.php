<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class FilesActionsRequest extends ParentIdBaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'all' => 'nullable|bool',
            'ids' => 'nullable|array',
            'ids.*' => Rule::exists('files', 'id')->where(function ($query) {
                $query->where('created_by', Auth::id());
            }),
        ]);
    }
}
