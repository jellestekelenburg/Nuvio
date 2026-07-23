import {
    computed,
    ref,
    type ComputedRef,
    type Ref,
    type WritableComputedRef,
} from 'vue';

type SelectableItem = {
    id: number;
};

type SelectionMode = 'explicit' | 'all';
type SelectAllState = boolean | 'indeterminate';

export type FileSelectionIntent =
    | {
          mode: 'ids';
          ids: number[];
      }
    | {
          mode: 'all';
          excludedIds: number[];
      };

export type FileSelection<T extends SelectableItem> = {
    selectAllState: WritableComputedRef<SelectAllState>;
    allSelected: ComputedRef<boolean>;
    selectsAll: ComputedRef<boolean>;
    hasExclusions: ComputedRef<boolean>;
    hasSelection: ComputedRef<boolean>;
    selectedIds: ComputedRef<number[]>;
    selectedItems: ComputedRef<T[]>;
    selectedCount: ComputedRef<number>;
    selection: ComputedRef<FileSelectionIntent>;
    isSelected: (item: T | number) => boolean;
    selectAll: () => void;
    toggle: (item: T, index: number, shiftKey?: boolean) => void;
    clear: () => void;
    selectionForAction: (item: T) => FileSelectionIntent;
};

/**
 * Manage explicit and folder-wide selection state for a paginated file list.
 *
 * Explicit selection contains individual loaded item ids. Folder-wide
 * selection represents every direct item in the current folder and records
 * individually deselected items as exclusions.
 */
export function useFileSelection<T extends SelectableItem>(
    items: Readonly<Ref<readonly T[]>>,
    totalItems: Readonly<Ref<number>>,
): FileSelection<T> {
    const selectionMode = ref<SelectionMode>('explicit');
    const explicitlySelectedIds = ref<Set<number>>(new Set());
    const excludedIds = ref<Set<number>>(new Set());
    const anchorIndex = ref<number | null>(null);

    const selectsAll = computed<boolean>(() => selectionMode.value === 'all');

    const hasExclusions = computed<boolean>(
        () => selectsAll.value && excludedIds.value.size > 0,
    );

    const selectedItems = computed<T[]>(() =>
        items.value.filter((item) => isSelected(item)),
    );

    const selectedIds = computed<number[]>(() =>
        selectedItems.value.map((item) => item.id),
    );

    const selectedCount = computed<number>(() => {
        if (selectsAll.value) {
            return Math.max(0, totalItems.value - excludedIds.value.size);
        }

        return selectedIds.value.length;
    });

    const hasSelection = computed<boolean>(() => selectedCount.value > 0);

    const allSelected = computed<boolean>(
        () => totalItems.value > 0 && selectsAll.value && !hasExclusions.value,
    );

    const selectAllState = computed<SelectAllState>({
        get: () => {
            if (!hasSelection.value) {
                return false;
            }

            return allSelected.value ? true : 'indeterminate';
        },
        set: (value) => {
            if (value === true) {
                selectAll();
                return;
            }

            clear();
        },
    });

    const selection = computed<FileSelectionIntent>(() => {
        if (selectsAll.value) {
            return {
                mode: 'all',
                excludedIds: [...excludedIds.value].sort(
                    (first, second) => first - second,
                ),
            };
        }

        return {
            mode: 'ids',
            ids: selectedIds.value,
        };
    });

    /**
     * Determine whether an item is part of the current selection.
     */
    function isSelected(item: T | number): boolean {
        const id = typeof item === 'number' ? item : item.id;

        if (selectionMode.value === 'all') {
            return !excludedIds.value.has(id);
        }

        return explicitlySelectedIds.value.has(id);
    }

    /**
     * Select every direct item in the current folder.
     *
     * Items loaded after this operation are selected automatically because
     * folder-wide selection does not depend on the currently loaded page.
     */
    function selectAll(): void {
        selectionMode.value = 'all';
        explicitlySelectedIds.value = new Set();
        excludedIds.value = new Set();
        anchorIndex.value = null;
    }

    /**
     * Toggle an item and optionally apply the same state to a contiguous range.
     *
     * In folder-wide mode, deselected items are stored as exclusions. In
     * explicit mode, selected items are stored as individual ids.
     */
    function toggle(item: T, index: number, shiftKey = false): void {
        const shouldSelect = !isSelected(item);
        const start =
            shiftKey && anchorIndex.value !== null
                ? Math.min(anchorIndex.value, index)
                : index;
        const end =
            shiftKey && anchorIndex.value !== null
                ? Math.max(anchorIndex.value, index)
                : index;
        const affectedItems = items.value.slice(start, end + 1);

        if (selectionMode.value === 'all') {
            const nextExcludedIds = new Set(excludedIds.value);

            for (const affectedItem of affectedItems) {
                if (shouldSelect) {
                    nextExcludedIds.delete(affectedItem.id);
                } else {
                    nextExcludedIds.add(affectedItem.id);
                }
            }

            excludedIds.value = nextExcludedIds;
        } else {
            const nextSelectedIds = new Set(explicitlySelectedIds.value);

            for (const affectedItem of affectedItems) {
                if (shouldSelect) {
                    nextSelectedIds.add(affectedItem.id);
                } else {
                    nextSelectedIds.delete(affectedItem.id);
                }
            }

            explicitlySelectedIds.value = nextSelectedIds;
        }

        anchorIndex.value = index;
    }

    /**
     * Remove the complete selection and reset the shift-selection anchor.
     */
    function clear(): void {
        selectionMode.value = 'explicit';
        explicitlySelectedIds.value = new Set();
        excludedIds.value = new Set();
        anchorIndex.value = null;
    }

    /**
     * Resolve the selection intent for an action initiated from a row.
     *
     * An action on a selected row carries the current selection. An action on
     * an unselected row carries only that specific item.
     */
    function selectionForAction(item: T): FileSelectionIntent {
        if (!isSelected(item)) {
            return {
                mode: 'ids',
                ids: [item.id],
            };
        }

        const currentSelection = selection.value;

        return currentSelection.mode === 'all'
            ? {
                  mode: 'all',
                  excludedIds: [...currentSelection.excludedIds],
              }
            : {
                  mode: 'ids',
                  ids: [...currentSelection.ids],
              };
    }

    return {
        selectAllState,
        allSelected,
        selectsAll,
        hasExclusions,
        hasSelection,
        selectedIds,
        selectedItems,
        selectedCount,
        selection,
        isSelected,
        selectAll,
        toggle,
        clear,
        selectionForAction,
    };
}
