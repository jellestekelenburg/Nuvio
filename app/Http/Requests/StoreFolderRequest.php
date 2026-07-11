<?php

namespace App\Http\Requests;

use App\Models\File;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreFolderRequest extends ParentIdBaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $parentId = $this->parent?->id
            ?? File::query()
                ->where('created_by', Auth::id())
                ->whereIsRoot()
                ->value('id');

        return array_merge(parent::rules(),
            [
                'name' => [
                    'required',
                    'string',
                    Rule::unique(File::class, 'name')
                        ->where('created_by', Auth::id())
                        ->where('parent_id', $parentId)
                        ->whereNull('deleted_at'),
                ],
            ]
        );
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Name already exists in this folder',
        ];
    }
}
