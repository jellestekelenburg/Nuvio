<script setup lang="ts">
import {
    AlertCircle,
    ChevronRight,
    Folder,
    Home,
    LoaderCircle,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useFolderBrowser } from '@/composables/useFolderBrowser';
import type { FolderPickerItem } from '@/types/folder-browser';

const props = withDefaults(
    defineProps<{
        initialFolderId: number;
        disabledFolderIds?: readonly number[];
    }>(),
    {
        disabledFolderIds: () => [],
    },
);

const selectedFolderId = defineModel<number | null>({
    default: null,
});

const {
    currentFolder,
    ancestors,
    folders,
    isLoading,
    isLoadingMore,
    error,
    hasMore,
    browse,
    loadMore,
} = useFolderBrowser();

const disabledIds = computed(() => new Set(props.disabledFolderIds));
const requestedFolderId = ref(props.initialFolderId);

const breadcrumbFolders = computed<FolderPickerItem[]>(() => {
    if (!currentFolder.value) {
        return [];
    }

    return [...(ancestors.value ?? []), currentFolder.value];
});

const currentFolderName = computed<string>(() =>
    currentFolder.value
        ? displayName(currentFolder.value)
        : 'Loading destination',
);

/**
 * Return the user-facing name for a folder.
 */
function displayName(folder: FolderPickerItem): string {
    return folder.is_root ? 'My Files' : folder.name;
}

/**
 * Determine whether a folder is unavailable as a destination.
 */
function isFolderDisabled(folder: FolderPickerItem): boolean {
    return disabledIds.value.has(folder.id);
}

/**
 * Load a folder and make it the active destination when allowed.
 */
async function openFolderById(folderId: number): Promise<void> {
    requestedFolderId.value = folderId;

    const loaded = await browse(folderId);
    const folder = currentFolder.value;

    if (
        !loaded ||
        !folder ||
        folder.id !== folderId ||
        isFolderDisabled(folder)
    ) {
        return;
    }

    selectedFolderId.value = folder.id;
}

/**
 * Navigate into a folder selected from the picker.
 */
function openFolder(folder: FolderPickerItem): void {
    if (isFolderDisabled(folder)) {
        return;
    }

    void openFolderById(folder.id);
}

/**
 * Retry loading the currently displayed or initial folder.
 */
function retry(): void {
    void openFolderById(requestedFolderId.value);
}

watch(
    () => props.initialFolderId,
    (folderId) => {
        void openFolderById(folderId);
    },
    {
        immediate: true,
    },
);
</script>

<template>
    <div class="space-y-4">
        <div class="rounded-lg border bg-muted/30 px-4 py-3" aria-live="polite">
            <p class="text-xs font-medium text-muted-foreground">
                Move destination
            </p>

            <div class="mt-1 flex items-center gap-2 font-medium">
                <Folder class="size-4 text-orange-500" />
                <span>{{ currentFolderName }}</span>
            </div>
        </div>

        <Breadcrumb v-if="breadcrumbFolders.length">
            <BreadcrumbList class="flex-nowrap overflow-x-auto pb-1">
                <template
                    v-for="(folder, index) in breadcrumbFolders"
                    :key="folder.id"
                >
                    <BreadcrumbSeparator v-if="index > 0" />

                    <BreadcrumbItem class="shrink-0">
                        <BreadcrumbPage
                            v-if="index === breadcrumbFolders.length - 1"
                            class="inline-flex items-center gap-1.5"
                        >
                            <Home v-if="folder.is_root" class="size-3.5" />
                            {{ displayName(folder) }}
                        </BreadcrumbPage>

                        <BreadcrumbLink
                            v-else
                            as="button"
                            type="button"
                            :disabled="isFolderDisabled(folder)"
                            class="inline-flex items-center gap-1.5 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="openFolder(folder)"
                        >
                            <Home v-if="folder.is_root" class="size-3.5" />
                            {{ displayName(folder) }}
                        </BreadcrumbLink>
                    </BreadcrumbItem>
                </template>
            </BreadcrumbList>
        </Breadcrumb>

        <Alert v-if="error" variant="destructive">
            <AlertCircle class="size-4" />
            <AlertTitle>Unable to load folders</AlertTitle>
            <AlertDescription>
                <p>{{ error }}</p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="mt-2"
                    @click="retry"
                >
                    Try again
                </Button>
            </AlertDescription>
        </Alert>

        <div v-if="isLoading" class="space-y-2">
            <Skeleton v-for="index in 5" :key="index" class="h-12 w-full" />
        </div>

        <div
            v-else-if="currentFolder"
            class="overflow-hidden rounded-lg border"
        >
            <div
                class="border-b bg-muted/30 px-4 py-2 text-xs font-medium text-muted-foreground"
            >
                Folders in {{ currentFolderName }}
            </div>

            <div v-if="!folders.length" class="px-4 py-8 text-center">
                <Folder class="mx-auto size-8 text-muted-foreground/50" />
                <p class="mt-2 text-sm font-medium">No subfolders</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    You can move the selected items into this folder.
                </p>
            </div>

            <ul
                v-else
                class="max-h-72 divide-y overflow-y-auto"
                aria-label="Available destination folders"
            >
                <li v-for="folder in folders" :key="folder.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/60 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="isFolderDisabled(folder)"
                        :title="
                            isFolderDisabled(folder)
                                ? 'The selected folder cannot be used as its own destination.'
                                : undefined
                        "
                        @click="openFolder(folder)"
                    >
                        <Folder class="size-4 text-orange-500" />

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium">
                                {{ displayName(folder) }}
                            </span>

                            <span
                                v-if="folder.has_children"
                                class="block text-xs text-muted-foreground"
                            >
                                Contains folders
                            </span>

                            <span
                                v-else-if="isFolderDisabled(folder)"
                                class="block text-xs text-destructive"
                            >
                                Cannot move here
                            </span>
                        </span>

                        <ChevronRight
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                    </button>
                </li>
            </ul>

            <div v-if="hasMore" class="border-t p-3 text-center">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="isLoadingMore"
                    @click="loadMore"
                >
                    <LoaderCircle v-if="isLoadingMore" class="animate-spin" />
                    {{ isLoadingMore ? 'Loading folders' : 'Load more' }}
                </Button>
            </div>
        </div>
    </div>
</template>
