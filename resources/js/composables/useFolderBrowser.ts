import { computed, onBeforeUnmount, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import folders from '@/routes/api/folders';
import type {
    FolderBrowserResponse,
    FolderPickerItem,
} from '@/types/folder-browser';

const PAGE_SIZE = 50;

export type FolderBrowser = {
    currentFolder: Ref<FolderPickerItem | null>;
    ancestors: Ref<FolderPickerItem[]>;
    folders: Ref<FolderPickerItem[]>;
    isLoading: Ref<boolean>;
    isLoadingMore: Ref<boolean>;
    error: Ref<string | null>;
    hasMore: ComputedRef<boolean>;
    browse: (folderId: number) => Promise<boolean>;
    loadMore: () => Promise<boolean>;
};

/**
 * Manage lazy folder navigation for the move destination picker.
 */
export function useFolderBrowser(): FolderBrowser {
    const currentFolder = ref<FolderPickerItem | null>(null);
    const ancestors = ref<FolderPickerItem[]>([]);
    const availableFolders = ref<FolderPickerItem[]>([]);
    const links = ref<FolderBrowserResponse['links'] | null>(null);
    const isLoading = ref(false);
    const isLoadingMore = ref(false);
    const error = ref<string | null>(null);

    let browseController: AbortController | null = null;
    let loadMoreController: AbortController | null = null;

    const hasMore = computed<boolean>(
        () => links.value?.next !== null && links.value?.next !== undefined,
    );

    /**
     * Load the first folder page for a specific parent.
     */
    async function browse(folderId: number): Promise<boolean> {
        browseController?.abort();
        loadMoreController?.abort();

        const controller = new AbortController();
        browseController = controller;
        loadMoreController = null;

        isLoading.value = true;
        isLoadingMore.value = false;
        error.value = null;

        try {
            const response = await requestFolderPage(
                folders.index.url({
                    query: {
                        parent_id: folderId,
                        page: 1,
                        per_page: PAGE_SIZE,
                    },
                }),
                controller.signal,
            );

            if (browseController !== controller) {
                return false;
            }

            applyPage(response, false);

            return true;
        } catch (requestError) {
            if (isAbortError(requestError)) {
                return false;
            }

            error.value = errorMessage(requestError);

            return false;
        } finally {
            if (browseController === controller) {
                browseController = null;
                isLoading.value = false;
            }
        }
    }

    /**
     * Append the next page of direct subfolders.
     */
    async function loadMore(): Promise<boolean> {
        const nextUrl = links.value?.next;

        if (!nextUrl || isLoading.value || isLoadingMore.value) {
            return false;
        }

        loadMoreController?.abort();

        const controller = new AbortController();
        loadMoreController = controller;
        isLoadingMore.value = true;
        error.value = null;

        try {
            const response = await requestFolderPage(
                nextUrl,
                controller.signal,
            );

            if (loadMoreController !== controller) {
                return false;
            }

            applyPage(response, true);

            return true;
        } catch (requestError) {
            if (isAbortError(requestError)) {
                return false;
            }

            error.value = errorMessage(requestError);

            return false;
        } finally {
            if (loadMoreController === controller) {
                loadMoreController = null;
                isLoadingMore.value = false;
            }
        }
    }

    /**
     * Apply a folder page to the current browser state.
     */
    function applyPage(response: FolderBrowserResponse, append: boolean): void {
        currentFolder.value = response.current;
        ancestors.value = response.ancestors;
        links.value = response.links;

        if (!append) {
            availableFolders.value = response.data;
            return;
        }

        const mergedFolders = new Map<number, FolderPickerItem>(
            availableFolders.value.map((folder) => [folder.id, folder]),
        );

        for (const folder of response.data) {
            mergedFolders.set(folder.id, folder);
        }

        availableFolders.value = [...mergedFolders.values()];
    }

    /**
     * Abort active folder requests when the consuming component unmounts.
     */
    onBeforeUnmount(() => {
        browseController?.abort();
        loadMoreController?.abort();
    });

    return {
        currentFolder,
        ancestors,
        folders: availableFolders,
        isLoading,
        isLoadingMore,
        error,
        hasMore,
        browse,
        loadMore,
    };
}

/**
 * Request one folder-browser page.
 */
async function requestFolderPage(
    url: string,
    signal: AbortSignal,
): Promise<FolderBrowserResponse> {
    const response = await fetch(url, {
        signal,
        headers: {
            Accept: 'application/json',
        },
    });

    if (!response.ok) {
        throw new Error(await responseErrorMessage(response));
    }

    return (await response.json()) as FolderBrowserResponse;
}

/**
 * Extract the most useful error message from a failed response.
 */
async function responseErrorMessage(response: Response): Promise<string> {
    try {
        const payload = (await response.json()) as {
            message?: unknown;
        };

        if (typeof payload.message === 'string') {
            return payload.message;
        }
    } catch {
        // The response did not contain JSON.
    }

    return 'Unable to load folders. Please try again.';
}

/**
 * Convert an unknown request failure into a user-facing message.
 */
function errorMessage(error: unknown): string {
    return error instanceof Error
        ? error.message
        : 'Unable to load folders. Please try again.';
}

/**
 * Determine whether a request was intentionally cancelled.
 */
function isAbortError(error: unknown): boolean {
    return error instanceof DOMException && error.name === 'AbortError';
}
