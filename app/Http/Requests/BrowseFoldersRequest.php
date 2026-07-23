<?php

namespace App\Http\Requests;

use App\Models\File;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BrowseFoldersRequest extends FormRequest
{
    private const int DEFAULT_PAGE_SIZE = 50;

    private const int MAX_PAGE_SIZE = 100;

    /**
     * Allow requests that passed the route authentication middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules for browsing folders.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'parent_id' => [
                'required',
                'integer',
                Rule::exists(File::class, 'id')
                    ->where(
                        fn (Builder $query) => $query
                            ->where('created_by', $userId)
                            ->where('is_folder', true)
                            ->whereNull('deleted_at'),
                    ),
            ],
            'page' => [
                'sometimes',
                'integer',
                'min:1',
            ],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.self::MAX_PAGE_SIZE,
            ],
        ];
    }

    /**
     * Return the validated folder being browsed.
     */
    public function parentId(): int
    {
        return (int) $this->validated('parent_id');
    }

    /**
     * Return the validated number of folders per page.
     */
    public function perPage(): int
    {
        return (int) (
            $this->validated('per_page')
            ?? self::DEFAULT_PAGE_SIZE
        );
    }
}
