<?php

namespace App\Data;

final readonly class FileMoveResult
{
    /**
     * @param  list<int>  $sourceParentIds
     * @param  list<array{
     *     id: int,
     *     old_name: string,
     *     new_name: string
     * }>  $renamedItems
     */
    public function __construct(
        public int $movedCount,
        public array $sourceParentIds,
        public int $targetParentId,
        public array $renamedItems
    ) {}

    /**
     * Return the number of automatically renamed items.
     */
    public function renamedCount(): int
    {
        return count($this->renamedItems);
    }

    /**
     * Convert the result into response-ready data.
     *
     * @return array{
     *     moved_count: int,
     *     renamed_count: int,
     *     source_parent_ids: list<int>,
     *     target_parent_id: int,
     *     renamed_items: list<array{
     *         id: int,
     *         old_name: string,
     *         new_name: string
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'moved_count' => $this->movedCount,
            'renamed_count' => $this->renamedCount(),
            'source_parent_ids' => $this->sourceParentIds,
            'target_parent_id' => $this->targetParentId,
            'renamed_items' => $this->renamedItems,
        ];
    }
}
