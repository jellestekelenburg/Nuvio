<?php

namespace App\Http\Requests;

use App\Data\FileMoveSelection;
use App\Enums\FileSelectionMode;
use App\Models\File;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class MoveFilesRequest extends FormRequest
{
    private const int MAX_EXPLICIT_ITEMS = 1000;

    private const int MAX_EXCLUDED_ITEMS = 1000;

    /**
     * Determine whether the authenticated user may make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules for a move request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->user()?->id;
        $sourceParentId = $this->integer(
            'selection.source_parent_id',
        );

        return [
            'selection' => [
                'required',
                'array:mode,ids,source_parent_id,excluded_ids',
            ],
            'selection.mode' => [
                'required',
                Rule::enum(FileSelectionMode::class),
            ],

            'selection.ids' => [
                'exclude_unless:selection.mode,ids',
                'required_if:selection.mode,ids',
                'array',
                'min:1',
                'max:'.self::MAX_EXPLICIT_ITEMS,
            ],
            'selection.ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(File::class, 'id')
                    ->where(
                        fn (Builder $query) => $query
                            ->where('created_by', $userId)
                            ->whereNull('deleted_at'),
                    ),
            ],

            'selection.source_parent_id' => [
                'exclude_unless:selection.mode,all',
                'required_if:selection.mode,all',
                'integer',
                Rule::exists(File::class, 'id')
                    ->where(
                        fn (Builder $query) => $query
                            ->where('created_by', $userId)
                            ->where('is_folder', true)
                            ->whereNull('deleted_at'),
                    ),
            ],
            'selection.excluded_ids' => [
                'exclude_unless:selection.mode,all',
                'sometimes',
                'array',
                'max:'.self::MAX_EXCLUDED_ITEMS,
            ],
            'selection.excluded_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(File::class, 'id')
                    ->where(
                        fn (Builder $query) => $query
                            ->where('created_by', $userId)
                            ->where('parent_id', $sourceParentId)
                            ->whereNull('deleted_at'),
                    ),
            ],

            'target_parent_id' => [
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
        ];
    }

    /**
     * Convert the validated request data into a domain selection.
     */
    public function moveSelection(): FileMoveSelection
    {
        $selection = $this->validated('selection');

        if (! is_array($selection)) {
            throw new LogicException(
                'A validated move selection must be an array.',
            );
        }

        $mode = FileSelectionMode::from(
            (string) $selection['mode'],
        );

        if ($mode === FileSelectionMode::All) {
            return FileMoveSelection::allFromFolder(
                sourceParentId: (int) $selection['source_parent_id'],
                excludedIds: $this->normalizeIds(
                    $selection['excluded_ids'] ?? [],
                ),
            );
        }

        return FileMoveSelection::fromIds(
            $this->normalizeIds($selection['ids'] ?? []),
        );
    }

    /**
     * Return the validated destination folder id.
     */
    public function targetParentId(): int
    {
        return (int) $this->validated('target_parent_id');
    }

    /**
     * Normalize validated identifiers into a list of integers.
     *
     * @return list<int>
     */
    private function normalizeIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            $ids,
        ));
    }
}
