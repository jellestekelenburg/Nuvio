<?php

namespace App\Data;

use App\Enums\FileSelectionMode;

final readonly class FileMoveSelection
{
    /**
     * @param  list<int>  $ids
     * @param  list<int>  $excludedIds
     */
    private function __construct(
        public FileSelectionMode $mode,
        public array $ids,
        public ?int $sourceParentId,
        public array $excludedIds,
    ) {}

    /**
     * Create a selection containing explicitly selected node ids.
     *
     * @param  list<int>  $ids
     */
    public static function fromIds(array $ids): self
    {
        return new self(
            mode: FileSelectionMode::Ids,
            ids: $ids,
            sourceParentId: null,
            excludedIds: [],
        );
    }

    /**
     * Create a selection containing every direct child of a folder.
     *
     * @param  list<int>  $excludedIds
     */
    public static function allFromFolder(
        int $sourceParentId,
        array $excludedIds = [],
    ): self {
        return new self(
            mode: FileSelectionMode::All,
            ids: [],
            sourceParentId: $sourceParentId,
            excludedIds: $excludedIds,
        );
    }

    /**
     * Determine whether the selection represents a complete folder listing.
     */
    public function selectsAll(): bool
    {
        return $this->mode === FileSelectionMode::All;
    }
}
