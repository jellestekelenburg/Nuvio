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
import FileIcon from '@/components/app/FileIcon.vue';
import RenameFileModal from '@/components/app/RenameFileModal.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { httpGet } from '@/composables/httpHelper';
import FileLayout from '@/layouts/FileLayout.vue';
import { myFiles } from '@/routes';

type FileListItem = {
    id: number;
    name: string;
    parent_id: number | null;
    owner: string;
    updated_at: string | null;
    size: string | null;
    is_folder: boolean;
    path: string | null;
};

type Paginated<T> = {
    links: any;
    data: T[];
};

type SortColumn = 'name' | 'updated_at' | 'size' | null;
type SortDirection = 'asc' | 'desc';

type SortState = {
    by: SortColumn;
    direction: SortDirection;
};

type TableColumn = {
    name: string;
    type: SortColumn;
};

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
const isLoadingMore = ref(false);
const allSelected = ref(false);
const createFolderModal = ref(false);
const renameFileModal = ref(false);
const fileToRename = ref<FileListItem | null>(null);
const lastSelectedFile = ref(0);
const selected = ref<Record<number, boolean>>({});
const selectedIds = computed(() =>
    Object.entries(selected.value)
        .filter((a) => a[1])
        .map((a) => a[0]),
);
const sort = computed(() => props.sort);

const currentFolderId = computed(() => props.folder?.id ?? null);
let observer: IntersectionObserver | null = null;

function openFolder(file: FileListItem): void {
    if (!file.is_folder || !file.path) {
        return;
    }

    router.visit(myFiles.get({ folder: file.path }));
}
function replaceFilesFromProps() {
    allFiles.value = {
        data: props.files.data,
        next: props.files.links.next,
    };

    allSelected.value = false;
    selected.value = {};

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

function onSelectAllChange() {
    allFiles.value.data.forEach((file) => {
        selected.value[file.id] = allSelected.value;
    });
}

function toggleFileSelect(
    file: FileListItem,
    index: number,
    isShiftPressed: boolean,
) {
    selected.value[file.id] = !selected.value[file.id];

    if (
        isShiftPressed &&
        lastSelectedFile.value !== index &&
        index - lastSelectedFile.value !== -1 &&
        index - lastSelectedFile.value !== 1
    ) {
        if (index - lastSelectedFile.value !== 1) {
            const min = Math.min(index, lastSelectedFile.value);
            const max = Math.max(index, lastSelectedFile.value);

            for (let i = min; i < max; i++) {
                const row = document.querySelector<HTMLElement>(
                    `[data-index="${i}"]`,
                );
                const fileId = row?.dataset.key;
                const numericFileId = Number(fileId);

                if (numericFileId) {
                    selected.value[numericFileId] = true;
                }
            }
        }
    }

    if (!selected.value[file.id]) {
        allSelected.value = false;
    } else {
        let checked = true;

        for (const file of allFiles.value.data) {
            if (!selected.value[file.id]) {
                checked = false;
                break;
            }
        }

        allSelected.value = checked;
    }

    lastSelectedFile.value = index;
}

function toggleSort(column: SortColumn) {
    if (!column) {
        return;
    }

    const nextDirection: SortDirection =
        props.sort.by === column && props.sort.direction === 'asc'
            ? 'desc'
            : 'asc';

    router.get(
        myFiles.url(
            props.folder?.path ? { folder: props.folder.path } : undefined,
        ),
        {
            sortBy: column,
            sortDirection: nextDirection,
        },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only: ['files', 'sort'],
            onSuccess: () => nextTick(loadMoreIfNeeded),
        },
    );
}

function onDelete() {
    allSelected.value = false;
    selected.value = {};
}

function showCreateFolderModal() {
    createFolderModal.value = true;
}

function showRenameModal(file: FileListItem) {
    fileToRename.value = file;
    renameFileModal.value = true;
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

onMounted(() => {
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
        <RenameFileModal v-model="renameFileModal" :file="fileToRename" />
        <div class="flex h-full min-h-0 flex-col">
            <div
                class="flex shrink-0 items-center justify-between border-b bg-white px-4 dark:bg-gray-800"
            >
                <BreadCrumbs :ancestors="ancestors"></BreadCrumbs>
                <div class="inline-flex gap-x-2">
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
                        <thead class="border-b">
                            <tr>
                                <th
                                    class="sticky top-0 z-10 w-6 bg-gray-100 py-4 ps-6 text-start text-sm font-medium dark:bg-gray-700"
                                >
                                    <Checkbox
                                        v-model="allSelected"
                                        @update:model-value="onSelectAllChange"
                                    >
                                    </Checkbox>
                                </th>
                                <th
                                    v-for="(item, code) in table"
                                    :key="code"
                                    class="z-10 flex-1 bg-gray-100 px-2 py-2.5 text-start text-sm font-medium text-gray-600 dark:bg-gray-500 dark:text-white"
                                    :class="item.type ? 'cursor-pointer' : ''"
                                    @click="toggleSort(item.type)"
                                >
                                    <span
                                        class="inline-flex items-center gap-0.5 rounded-xl px-4 py-1.5"
                                        :class="
                                            item.type && sort.by === item.type
                                                ? 'font-black text-gray-950'
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
                                    class="cursor-pointer transition duration-300 ease-in-out select-none not-last:border-b"
                                    :class="
                                        selected[file.id] || allSelected
                                            ? 'bg-blue-50 hover:bg-blue-100'
                                            : 'bg-white hover:bg-gray-100 dark:border-b-gray-600 dark:bg-gray-800'
                                    "
                                >
                                    <td
                                        class="w-4 items-center gap-2 py-4 ps-6 text-sm font-medium whitespace-nowrap text-gray-900 dark:text-white"
                                    >
                                        <Checkbox
                                            :model-value="
                                                allSelected ||
                                                !!selected[file.id]
                                            "
                                        />
                                    </td>
                                    <td
                                        class="inline-flex items-center gap-2 px-6 py-4 text-sm font-medium whitespace-nowrap text-gray-900 dark:text-white"
                                    >
                                        <FileIcon :file="file"></FileIcon>
                                        {{ file.name }}
                                    </td>
                                    <td
                                        class="px-6 py-4 text-sm font-medium whitespace-nowrap text-gray-900 dark:text-white"
                                    >
                                        {{ file.owner }}
                                    </td>
                                    <td
                                        class="px-6 py-4 text-sm font-medium whitespace-nowrap text-gray-900 dark:text-white"
                                    >
                                        {{ file.updated_at }}
                                    </td>
                                    <td
                                        class="px-6 py-4 text-sm font-medium whitespace-nowrap text-gray-900 dark:text-white"
                                    >
                                        {{ file.size }}
                                    </td>
                                </tr>
                            </FileContextMenu>
                        </tbody>
                    </table>

                    <div
                        v-if="!allFiles.data.length"
                        class="py-8 text-center text-sm text-gray-400"
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
