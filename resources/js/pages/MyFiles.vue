<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import BreadCrumbs from '@/components/app/BreadCrumbs.vue';
import CreateFolderModal from '@/components/app/CreateFolderModal.vue';
import CreateNewContextMenu from '@/components/app/CreateNewContextMenu.vue';
import CreateNewDropdown from '@/components/app/CreateNewDropdown.vue';
import DeleteFilesButton from '@/components/app/DeleteFilesButton.vue';
import DownloadFilesButton from '@/components/app/DownloadFilesButton.vue';
import FileContextMenu from '@/components/app/FileContextMenu.vue';
import FileDetailsModal from '@/components/app/FileDetailsModal.vue';
import FileIcon from '@/components/app/FileIcon.vue';
import MoveFilesButton from '@/components/app/MoveFilesButton.vue';
import RenameFileModal from '@/components/app/RenameFileModal.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { httpGet } from '@/composables/httpHelper';
import { useFileSelection } from '@/composables/useFileSelection';
import FileLayout from '@/layouts/FileLayout.vue';
import type { File } from '@/lib/types';
import { myFiles } from '@/routes';

type FileListItem = File;

type Paginated<T> = {
    links: {
        next: string | null;
    };
    meta: {
        total: number;
    };
    data: T[];
};

type SortColumn = 'name' | 'updated_at' | 'size' | null;
type ActiveSortColumn = Exclude<SortColumn, null>;
type SortDirection = 'asc' | 'desc';

type SortState = {
    by: SortColumn;
    direction: SortDirection;
};

type ActiveSortState = {
    by: ActiveSortColumn;
    direction: SortDirection;
};

type TableColumn = {
    name: string;
    type: SortColumn;
};

const SORT_STORAGE_KEY = 'file-manager.my-files.sort';

const table: Record<number, TableColumn> = {
    1: { name: 'Name', type: 'name' },
    2: { name: 'Owner', type: null },
    3: { name: 'Last modified', type: 'updated_at' },
    4: { name: 'Size', type: 'size' },
};

const props = withDefaults(
    defineProps<{
        files: Paginated<FileListItem>;
        folder?: FileListItem | null;
        ancestors?: { data: FileListItem[] };
        sort?: SortState;
        storage?: void;
    }>(),
    {
        folder: null,
        ancestors: () => ({ data: [] }),
        sort: () => ({ by: 'size', direction: 'desc' }),
    },
);
const scrollContainer = ref<HTMLElement | null>(null);
const loadMoreIntersect = ref<HTMLElement | null>(null);
const allFiles = ref({
    data: props.files.data,
    next: props.files.links.next,
});

const selectableFiles = computed(() => allFiles.value.data);
const totalFiles = computed(() => props.files.meta.total);

const {
    selectAllState,
    allSelected,
    selectedIds,
    selectedItems,
    selectedCount,
    selection,
    isSelected,
    toggle: toggleFileSelect,
    clear: clearSelection,
} = useFileSelection(selectableFiles, totalFiles);

const isLoadingMore = ref(false);
const createFolderModal = ref(false);
const renameFileModal = ref(false);
const detailFileModal = ref(false);
const selectedFile = ref<FileListItem | null>(null);
const sort = computed(() => props.sort);

const currentFolderId = computed(() => props.folder?.id ?? null);
let observer: IntersectionObserver | null = null;

function openFolder(file: FileListItem): void {
    if (!file.is_folder) {
        return;
    }

    router.visit(
        myFiles.get(
            { folder: file.id },
            {
                query: sortQuery(activeSort(props.sort)),
            },
        ),
    );
}

function replaceFilesFromProps() {
    allFiles.value = {
        data: props.files.data,
        next: props.files.links.next,
    };

    clearSelection();

    nextTick(loadMoreIfNeeded);
}

function loadMore() {
    if (allFiles.value.next === null || isLoadingMore.value) {
        return;
    }

    isLoadingMore.value = true;
    httpGet(allFiles.value.next)
        .then((res) => {
            allFiles.value.data = [...allFiles.value.data, ...res.data];
            allFiles.value.next = res.links.next;

            nextTick(loadMoreIfNeeded);
        })
        .finally(() => {
            isLoadingMore.value = false;
        });
}

function loadMoreIfNeeded() {
    if (
        !scrollContainer.value ||
        !loadMoreIntersect.value ||
        allFiles.value.next === null ||
        isLoadingMore.value
    ) {
        return;
    }

    const scrollRect = scrollContainer.value.getBoundingClientRect();
    const intersectRect = loadMoreIntersect.value.getBoundingClientRect();

    if (intersectRect.top <= scrollRect.bottom + 250) {
        loadMore();
    }
}

function toggleSort(column: SortColumn) {
    if (!column) {
        return;
    }

    const nextDirection: SortDirection =
        props.sort.by === column && props.sort.direction === 'asc'
            ? 'desc'
            : 'asc';

    const nextSort: ActiveSortState = {
        by: column,
        direction: nextDirection,
    };

    storeSortPreference(nextSort);
    visitCurrentFolderWithSort(nextSort);
}

/**
 * Visit the current folder using a specific sort state.
 */
function visitCurrentFolderWithSort(sortState: ActiveSortState): void {
    router.get(
        myFiles.url(
            props.folder?.parent_id ? { folder: props.folder.id } : undefined,
        ),
        sortQuery(sortState),
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only: ['files', 'sort'],
            onSuccess: () => nextTick(loadMoreIfNeeded),
        },
    );
}

/**
 * Apply an explicit URL sort or restore the stored sort preference.
 */
function syncSortPreference(): void {
    const currentSort = activeSort(props.sort);

    if (hasExplicitSortQuery()) {
        storeSortPreference(currentSort);
        return;
    }

    const storedSort = readSortPreference();

    if (!storedSort) {
        storeSortPreference(currentSort);
        return;
    }

    if (!sameSort(currentSort, storedSort)) {
        visitCurrentFolderWithSort(storedSort);
    }
}

/**
 * Normalize a server-provided sort state into an active sortable column.
 */
function activeSort(sortState: SortState): ActiveSortState {
    return {
        by: sortState.by ?? 'size',
        direction: sortState.direction,
    };
}

/**
 * Convert a sort state into request query parameters.
 */
function sortQuery(sortState: ActiveSortState): {
    sortBy: ActiveSortColumn;
    sortDirection: SortDirection;
} {
    return {
        sortBy: sortState.by,
        sortDirection: sortState.direction,
    };
}

/**
 * Determine whether the current URL intentionally specifies sorting.
 */
function hasExplicitSortQuery(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    const query = new URLSearchParams(window.location.search);

    return query.has('sortBy') || query.has('sortDirection');
}

/**
 * Read and validate the saved sorting preference.
 */
function readSortPreference(): ActiveSortState | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        const storedValue = window.localStorage.getItem(SORT_STORAGE_KEY);

        if (!storedValue) {
            return null;
        }

        const value = JSON.parse(storedValue) as {
            by?: unknown;
            direction?: unknown;
        };

        if (
            !isActiveSortColumn(value.by) ||
            !isSortDirection(value.direction)
        ) {
            return null;
        }

        return {
            by: value.by,
            direction: value.direction,
        };
    } catch {
        return null;
    }
}

/**
 * Store a sorting preference when browser storage is available.
 */
function storeSortPreference(sortState: ActiveSortState): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(
            SORT_STORAGE_KEY,
            JSON.stringify(sortState),
        );
    } catch {
        // Storage can be unavailable in restricted browser environments.
    }
}

/**
 * Determine whether two sorting states are identical.
 */
function sameSort(first: ActiveSortState, second: ActiveSortState): boolean {
    return first.by === second.by && first.direction === second.direction;
}

/**
 * Determine whether a value is a supported sortable column.
 */
function isActiveSortColumn(value: unknown): value is ActiveSortColumn {
    return value === 'name' || value === 'updated_at' || value === 'size';
}

/**
 * Determine whether a value is a supported sorting direction.
 */
function isSortDirection(value: unknown): value is SortDirection {
    return value === 'asc' || value === 'desc';
}

function onDelete() {
    clearSelection();
}

function showCreateFolderModal() {
    createFolderModal.value = true;
}

function showRenameModal(file: FileListItem) {
    selectedFile.value = file;
    renameFileModal.value = true;
}

function showDetailModal(file: FileListItem) {
    selectedFile.value = file;
    detailFileModal.value = true;
}

watch(
    () => currentFolderId.value,
    (newFolderId, oldFolderId) => {
        if (newFolderId === oldFolderId) {
            return;
        }

        replaceFilesFromProps();
    },
);

watch(
    () => [props.files.data, props.files.links.next],
    () => {
        replaceFilesFromProps();
    },
);

watch(
    () => [currentFolderId.value, props.sort.by, props.sort.direction] as const,
    () => {
        syncSortPreference();
    },
);

onMounted(() => {
    syncSortPreference();

    if (!scrollContainer.value || !loadMoreIntersect.value) {
        return;
    }

    observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => entry.isIntersecting && loadMore());
        },
        {
            root: scrollContainer.value,
            rootMargin: '0px 0px 250px 0px',
        },
    );

    observer.observe(loadMoreIntersect.value);
    nextTick(loadMoreIfNeeded);
});

onBeforeUnmount(() => {
    observer?.disconnect();
});
</script>

<template>
    <Head title="Dashboard" />
    <FileLayout>
        <CreateFolderModal v-model="createFolderModal" />
        <FileDetailsModal v-model="detailFileModal" :file="selectedFile" />
        <RenameFileModal v-model="renameFileModal" :file="selectedFile" />

        <div class="flex h-full min-h-0 flex-col">
            <div
                class="flex shrink-0 items-center justify-between border-b border-file-border bg-file-toolbar px-4"
            >
                <BreadCrumbs :ancestors="ancestors"></BreadCrumbs>
                <div class="inline-flex gap-x-2">
                    <MoveFilesButton
                        :selection="selection"
                        :selected-items="selectedItems"
                        :selected-count="selectedCount"
                        :source-folder-id="currentFolderId"
                        @moved="clearSelection"
                    />
                    <DeleteFilesButton
                        :delete-all="allSelected"
                        :delete-ids="selectedIds"
                        @delete="onDelete"
                    ></DeleteFilesButton>
                    <DownloadFilesButton
                        :download-all="allSelected"
                        :download-ids="selectedIds"
                    ></DownloadFilesButton>
                    <CreateNewDropdown
                        button-class="h-9"
                        @create-folder="showCreateFolderModal"
                    />
                </div>
            </div>

            <CreateNewContextMenu @create-folder="showCreateFolderModal">
                <div ref="scrollContainer" class="min-h-0 flex-1 overflow-auto">
                    <table class="relative min-w-full">
                        <thead class="border-b border-file-border">
                            <tr>
                                <th
                                    class="sticky top-0 z-10 w-6 bg-file-table-header py-4 ps-6 text-start text-sm font-medium"
                                >
                                    <Checkbox v-model="selectAllState">
                                    </Checkbox>
                                </th>
                                <th
                                    v-for="(item, code) in table"
                                    :key="code"
                                    class="z-10 flex-1 bg-file-table-header px-2 py-2.5 text-start text-sm font-medium text-file-header-foreground"
                                    :class="item.type ? 'cursor-pointer' : ''"
                                    @click="toggleSort(item.type)"
                                >
                                    <span
                                        class="inline-flex items-center gap-0.5 rounded-xl px-4 py-1.5"
                                        :class="
                                            item.type && sort.by === item.type
                                                ? 'font-black text-file-foreground'
                                                : ''
                                        "
                                    >
                                        {{ item.name }}

                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            viewBox="0 0 640 640"
                                            class="inline-block size-4 fill-current"
                                            v-if="
                                                item.type &&
                                                sort.by === item.type
                                            "
                                            :class="
                                                item.type &&
                                                sort.by === item.type &&
                                                sort.direction === 'desc'
                                                    ? 'rotate-180'
                                                    : ''
                                            "
                                        >
                                            <path
                                                d="M320.3 461.3L502.9 278.7L525.5 256.1L480.2 210.8L457.6 233.4L320.2 370.8L182.8 233.4L160.2 210.8L114.9 256.1L137.5 278.7L297.5 438.7L320.1 461.3z"
                                            />
                                        </svg>
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <FileContextMenu
                                v-for="(file, index) of allFiles.data"
                                :key="file.id"
                                @rename="showRenameModal(file)"
                                @details="showDetailModal(file)"
                                @create-folder="showCreateFolderModal"
                            >
                                <tr
                                    :data-index="index"
                                    :data-key="file.id"
                                    @dblclick="openFolder(file)"
                                    @click="
                                        toggleFileSelect(
                                            file,
                                            index,
                                            $event.shiftKey,
                                        )
                                    "
                                    class="cursor-pointer border-file-border transition duration-300 ease-in-out select-none not-last:border-b"
                                    :class="
                                        isSelected(file)
                                            ? 'bg-file-row-selected hover:bg-file-row-selected-hover'
                                            : 'bg-file-row hover:bg-file-row-hover'
                                    "
                                >
                                    <td
                                        class="w-4 items-center gap-2 py-4 ps-6 text-sm font-medium whitespace-nowrap text-file-foreground"
                                    >
                                        <Checkbox
                                            :model-value="isSelected(file)"
                                        />
                                    </td>
                                    <td
                                        class="inline-flex items-center gap-2 px-6 py-4 text-sm font-medium whitespace-nowrap text-file-foreground"
                                    >
                                        <FileIcon :file="file"></FileIcon>
                                        {{ file.name }}
                                    </td>
                                    <td
                                        class="px-6 py-4 text-sm font-medium whitespace-nowrap text-file-foreground"
                                    >
                                        {{ file.owner }}
                                    </td>
                                    <td
                                        class="px-6 py-4 text-sm font-medium whitespace-nowrap text-file-foreground"
                                    >
                                        {{ file.updated_at }}
                                    </td>
                                    <td
                                        class="px-6 py-4 text-sm font-medium whitespace-nowrap text-file-foreground"
                                    >
                                        {{ file.size }}
                                    </td>
                                </tr>
                            </FileContextMenu>
                        </tbody>
                    </table>

                    <div
                        v-if="!allFiles.data.length"
                        class="py-8 text-center text-sm text-file-muted-foreground"
                    >
                        There is no data in this folder.
                    </div>

                    <div ref="loadMoreIntersect"></div>
                </div>
            </CreateNewContextMenu>
        </div>
    </FileLayout>
</template>

<style scoped></style>
