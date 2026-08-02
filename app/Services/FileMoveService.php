<?php

namespace App\Services;

use App\Data\FileMoveResult;
use App\Data\FileMoveSelection;
use App\Exceptions\FileMoveException;
use App\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

final class FileMoveService
{
    public function __construct(
        private readonly AvailableNodeNameService $availableNodeNameService,
        private readonly FileTreeMutationService $fileTreeMutationService,
        private readonly FileListCache $fileListCache
    ) {}

    /**
     * Move a validated selection into a target folder.
     *
     * Every selected top-level node is moved atomically. Selected descendants
     * are ignored when one of their ancestors is also selected. After commit,
     * every changed source and target listing is invalidated.
     */
    public function move(
        User $user,
        FileMoveSelection $selection,
        int $targetParentId,
    ): FileMoveResult {
        $result = $this->fileTreeMutationService->run(
            $user->id,
            function () use (
                $user,
                $selection,
                $targetParentId,
            ): FileMoveResult {
                $targetParent = $this->resolveTargetParent(
                    $user,
                    $targetParentId,
                );

                $selectedNodes = $this->resolveSelection(
                    $user,
                    $selection,
                );

                $this->validateBatch($selectedNodes, $targetParent);

                $topLevelNodes = $this->topLevelNodes($selectedNodes);

                return $this->moveTopLevelNodes(
                    user: $user,
                    nodes: $topLevelNodes,
                    targetParent: $targetParent,
                );
            },
        );

        $this->flushChangedListings($user, $result);

        return $result;
    }

    /**
     * Retrieve and lock the active target folder.
     */
    private function resolveTargetParent(
        User $user,
        int $targetParentId,
    ): File {
        $targetParent = File::query()
            ->whereKey($targetParentId)
            ->where('created_by', $user->id)
            ->where('is_folder', true)
            ->lockForUpdate()
            ->first();

        if (
            ! $targetParent instanceof File
            || ! $targetParent->isAvailableTreeTarget()
        ) {
            throw new FileMoveException(
                'The destination folder is no longer available.'
            );
        }

        return $targetParent;
    }

    /**
     * Resolve the domain selection into locked file records.
     *
     * @return Collection<int, File>
     */
    private function resolveSelection(
        User $user,
        FileMoveSelection $selection,
    ): Collection {
        if ($selection->selectsAll()) {
            return $this->resolveFolderSelection($user, $selection);
        }

        return $this->resolveExplicitSelection($user, $selection);
    }

    /**
     * Retrieve and lock explicitly selected nodes.
     *
     * @return Collection<int, File>
     */
    private function resolveExplicitSelection(
        User $user,
        FileMoveSelection $selection,
    ): Collection {
        $ids = $this->uniqueIds($selection->ids);

        if ($ids === []) {
            throw new FileMoveException(
                'Please select at least one file or folder.'
            );
        }

        $nodes = File::query()
            ->where('created_by', $user->id)
            ->whereKey($ids)
            ->orderBy('_lft')
            ->lockForUpdate()
            ->get();

        if ($nodes->count() !== count($ids)) {
            throw new FileMoveException(
                'One or more selected items are no longer available.'
            );
        }

        if ($nodes->contains(
            fn (File $node): bool => ! $node->isInAvailableTree(),
        )) {
            throw new FileMoveException(
                'One or more selected items are no longer available.',
            );
        }

        return $nodes;
    }

    /**
     * Retrieve every direct child of a locked source folder.
     *
     * @return Collection<int, File>
     */
    private function resolveFolderSelection(
        User $user,
        FileMoveSelection $selection,
    ): Collection {
        if ($selection->sourceParentId === null) {
            throw new FileMoveException(
                'A source folder is required for select all.'
            );
        }

        $sourceParent = File::query()
            ->whereKey($selection->sourceParentId)
            ->where('created_by', $user->id)
            ->where('is_folder', true)
            ->lockForUpdate()
            ->first();

        if (
            ! $sourceParent instanceof File
            || ! $sourceParent->isAvailableTreeTarget()
        ) {
            throw new FileMoveException(
                'The source folder is no longer available.'
            );
        }

        $excludedIds = $this->uniqueIds(
            $selection->excludedIds,
        );

        $this->validateExcludedNodes(
            user: $user,
            sourceParent: $sourceParent,
            excludedIds: $excludedIds,
        );

        return File::query()
            ->where('created_by', $user->id)
            ->where('parent_id', $sourceParent->id)
            ->when(
                $excludedIds !== [],
                fn ($query) => $query->whereNotIn('id', $excludedIds)
            )
            ->orderBy('_lft')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Ensure every exclusion still belongs directly to the source folder.
     *
     * @param  list<int>  $excludedIds
     */
    private function validateExcludedNodes(
        User $user,
        File $sourceParent,
        array $excludedIds,
    ): void {
        if ($excludedIds === []) {
            return;
        }

        $excludedNodes = File::query()
            ->where('created_by', $user->id)
            ->where('parent_id', $sourceParent->id)
            ->whereKey($excludedIds)
            ->lockForUpdate()
            ->get(['id']);

        if ($excludedNodes->count() !== count($excludedIds)) {
            throw new FileMoveException(
                'One or more selection exclusions are no longer valid.'
            );
        }
    }

    /**
     * Validate the complete batch before performing the first mutation.
     *
     * @param  Collection<int, File>  $nodes
     */
    private function validateBatch(
        Collection $nodes,
        File $targetParent,
    ): void {
        foreach ($nodes as $node) {
            if ($node->isRoot()) {
                throw new FileMoveException(
                    'The root folder cannot be moved.',
                );
            }

            if (
                $node->is_folder &&
                (
                    $targetParent->is($node) ||
                    $targetParent->isDescendantOf($node)
                )
            ) {
                throw new FileMoveException(
                    'A folder cannot be moved into itself or one of its descendants.',
                );
            }
        }
    }

    /**
     * Remove selected descendants when their ancestor is also selected.
     *
     * @param  Collection<int, File>  $nodes
     * @return Collection<int, File>
     */
    private function topLevelNodes(Collection $nodes): Collection
    {
        $topLevelNodes = new Collection;

        foreach ($nodes->sortBy('_lft') as $node) {
            $hasSelectedAncestor = $topLevelNodes->contains(
                fn (File $candidate): bool => $node
                    ->isDescendantOf($candidate),
            );

            if ($hasSelectedAncestor) {
                continue;
            }

            $topLevelNodes->push($node);
        }

        return $topLevelNodes;
    }

    /**
     * Move every top-level node while preserving its original tree order.
     *
     * @param  Collection<int, File>  $nodes
     */
    private function moveTopLevelNodes(
        User $user,
        Collection $nodes,
        File $targetParent,
    ): FileMoveResult {
        $movedCount = 0;
        $sourceParentIds = [];
        $renamedItems = [];
        $reservedNames = [];

        foreach ($nodes as $selectedNode) {
            $node = File::query()
                ->whereKey($selectedNode->id)
                ->where('created_by', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $node instanceof File) {
                throw new FileMoveException(
                    'A selected item is no longer available.',
                );
            }

            if ($node->parent_id === $targetParent->id) {
                continue;
            }

            $oldName = $node->name;
            $sourceParentIds[] = (int) $node->parent_id;

            $availableName = $this->availableNodeNameService->generate(
                targetParent: $targetParent,
                requestedName: $oldName,
                reservedNames: $reservedNames,
                ignoreNodeId: $node->id,
            );

            $node->name = $availableName;
            $node->updated_by = $user->id;

            if (! $node->appendToNode($targetParent)->save()) {
                throw new RuntimeException(
                    'The selected item could not be moved.',
                );
            }

            $reservedNames[] = $availableName;
            $movedCount++;

            if ($availableName !== $oldName) {
                $renamedItems[] = [
                    'id' => (int) $node->id,
                    'old_name' => $oldName,
                    'new_name' => $availableName,
                ];
            }
        }

        $sourceParentIds = array_values(array_unique(
            $sourceParentIds,
        ));
        sort($sourceParentIds);

        return new FileMoveResult(
            movedCount: $movedCount,
            sourceParentIds: $sourceParentIds,
            targetParentId: (int) $targetParent->id,
            renamedItems: $renamedItems
        );
    }

    /**
     * Remove duplicate identifiers while preserving their original order.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function uniqueIds(array $ids): array
    {
        return array_values(array_unique($ids));
    }

    /**
     * Invalidate every folder listing changed by a committed move.
     */
    private function flushChangedListings(
        User $user,
        FileMoveResult $result,
    ): void {
        if ($result->movedCount === 0) {
            return;
        }

        $folderIds = array_values(array_unique([
            ...$result->sourceParentIds,
            $result->targetParentId,
        ]));

        foreach ($folderIds as $folderId) {
            $this->fileListCache->flushFolder(
                $user,
                $folderId,
            );
        }
    }
}
