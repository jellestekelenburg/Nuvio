<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CreateFolderModal from '@/components/app/CreateFolderModal.vue';
import CreateNewContextMenu from '@/components/app/CreateNewContextMenu.vue';
import DeleteForeverButton from '@/components/app/DeleteForeverButton.vue';
import FileIcon from '@/components/app/FileIcon.vue';
import RestoreFilesButton from '@/components/app/RestoreFilesButton.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { httpGet } from '@/composables/httpHelper';
import FileLayout from '@/layouts/FileLayout.vue';

type FileListItem = {
    id: number;
    name: string;
    parent_id: number | null;
    owner: string;
    updated_at: string | null;
    size: string | null;
    is_folder: boolean;
};

type Paginated<T> = {
    links: any;
    data: T[];
};

const props = withDefaults(
    defineProps<{
        files: Paginated<FileListItem>;
        folder?: FileListItem | null;
        ancestors?: { data: FileListItem[] };
        storage?: void;
    }>(),
    {
        folder: null,
        ancestors: () => ({ data: [] }),
    },
);
const loadMoreIntersect = ref(null);
const allFiles = ref({
    data: props.files.data,
    next: props.files.links.next,
});
const isLoadingMore = ref(false);
const allSelected = ref(false);
const createFolderModal = ref(false);
const lastSelectedFile = ref(0);
const selected = ref<Record<number, boolean>>({});
const selectedIds = computed(() =>
    Object.entries(selected.value)
        .filter((a) => a[1])
        .map((a) => a[0]),
);

const currentFolderId = computed(() => props.folder?.id ?? null);
let observer: IntersectionObserver | null = null;

function mergeIncomingTopPage(
    incoming: FileListItem[],
    previousTopPage: FileListItem[] = [],
) {
    const incomingIds = new Set(incoming.map((file) => file.id));
    const previousTopPageIds = new Set(previousTopPage.map((file) => file.id));
    const existingTail = allFiles.value.data.filter(
        (file) => !incomingIds.has(file.id) && !previousTopPageIds.has(file.id),
    );

    allFiles.value.data = [...incoming, ...existingTail];
    allFiles.value.next = props.files.links.next;
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
        })
        .finally(() => {
            isLoadingMore.value = false;
        });
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

            console.log(min, max);

            for (let i = min; i < max; i++) {
                const fileId = document.querySelector<HTMLElement>(
                    `[data-index="${i}"]`,
                )?.dataset?.key;

                if (!fileId) return;

                const numericFileId = Number(fileId);

                if (!Number.isNaN(numericFileId)) {
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

function showCreateFolderModal() {
    createFolderModal.value = true;
}

watch(
    () => currentFolderId.value,
    (newFolderId, oldFolderId) => {
        if (newFolderId === oldFolderId) {
            return;
        }

        allFiles.value = {
            data: props.files.data,
            next: props.files.links.next,
        };
    },
);

watch(
    () => props.files.data,
    (incoming, previous = []) => {
        mergeIncomingTopPage(incoming, previous);
    },
);

onMounted(() => {
    if (!loadMoreIntersect.value) {
        return;
    }

    observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => entry.isIntersecting && loadMore());
        },
        {
            rootMargin: '-250px 0px 0px 0px',
        },
    );

    observer.observe(loadMoreIntersect.value);
});

onBeforeUnmount(() => {
    observer?.disconnect();
});
</script>

<template>
    <Head title="Dashboard" />
    <FileLayout>
        <CreateFolderModal v-model="createFolderModal" />
        <div class="flex h-full min-h-0 flex-col">
            <div
                class="flex shrink-0 items-center justify-between border-b bg-white px-4 dark:bg-gray-800"
            >
                <div></div>
                <div class="inline-flex gap-x-2 py-4">
                    <RestoreFilesButton
                        :restore-all="allSelected"
                        :restore-ids="selectedIds"
                    ></RestoreFilesButton>
                    <DeleteForeverButton
                        :delete-all="allSelected"
                        :delete-ids="selectedIds"
                    >
                    </DeleteForeverButton>
                </div>
            </div>

            <CreateNewContextMenu @create-folder="showCreateFolderModal">
                <div class="min-h-0 flex-1 overflow-auto">
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
                                    class="sticky top-0 z-10 bg-gray-100 px-6 py-4 text-start text-sm font-medium text-gray-900 dark:bg-gray-700 dark:text-white"
                                >
                                    Name
                                </th>
                                <th
                                    class="sticky top-0 z-10 bg-gray-100 px-6 py-4 text-start text-sm font-medium text-gray-900 dark:bg-gray-700 dark:text-white"
                                >
                                    Size
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(file, index) of allFiles.data"
                                :key="file.id"
                                :data-index="index"
                                :data-key="file.id"
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
                                            allSelected || !!selected[file.id]
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
                                    {{ file.size }}
                                </td>
                            </tr>
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
