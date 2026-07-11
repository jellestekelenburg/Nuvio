<?php

namespace App\Http\Requests;

use App\Models\File;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ParentIdBaseRequest extends FormRequest
{
    public ?File $parent = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $this->parent = File::query()->where('id', $this->input('parent_id'))->first();

        if ($this->parent && ! $this->parent->isOwnedBy(Auth::id())) {
            return false;
        }

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
            'parent_id' => [
                'nullable',
                Rule::exists('files', 'id')->where(function (Builder $query) {
                    return $query
                        ->where('is_folder', true)
                        ->where('created_by', auth()->id());
                }),
            ],
        ];
    }
}
