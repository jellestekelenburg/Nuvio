import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import {
    showErrorNotification,
    showSuccessNotification,
} from '@/composables/event-bus';
import type { FileSelectionIntent } from '@/composables/useFileSelection';
import { move } from '@/routes/file';
import type {
    FileMovePayload,
    FileMovePayloadSelection,
    FileMoveResult,
} from '@/types/file-move';

export type FileMoveSubmission = {
    selection: FileSelectionIntent;
    sourceParentId: number;
    targetParentId: number;
    onSuccess?: (result: FileMoveResult | null) => void;
};

export type MoveFiles = {
    processing: ComputedRef<boolean>;
    error: Readonly<Ref<string | null>>;
    submit: (submission: FileMoveSubmission) => boolean;
    reset: () => void;
};

/**
 * Submit file moves through the shared move endpoint.
 */
export function useMoveFiles(): MoveFiles {
    const form = useForm<FileMovePayload>({
        selection: {
            mode: 'ids',
            ids: [],
        },
        target_parent_id: null,
    });

    const error = ref<string | null>(null);
    const processing = computed<boolean>(() => form.processing);

    /**
     * Submit a normalized move request when no request is already active.
     */
    function submit(submission: FileMoveSubmission): boolean {
        if (form.processing) {
            return false;
        }

        error.value = null;
        form.clearErrors();
        form.selection = moveSelectionPayload(
            submission.selection,
            submission.sourceParentId,
        );
        form.target_parent_id = submission.targetParentId;

        form.patch(move().url, {
            preserveScroll: true,
            only: ['files', 'folder', 'ancestors', 'flash'],
            onSuccess: (page) => {
                const result = extractMoveResult(page.props);

                showSuccessNotification(moveSuccessMessage(result));
                submission.onSuccess?.(result);
            },
            onError: (errors) => {
                const message = moveErrorMessage(errors);

                error.value = message;
                showErrorNotification(message);
            },
        });

        return true;
    }

    /**
     * Clear request state before opening or closing a move interface.
     */
    function reset(): void {
        form.reset();
        form.clearErrors();
        error.value = null;
    }

    return {
        processing,
        error,
        submit,
        reset,
    };
}

/**
 * Convert frontend selection state into the API request format.
 */
function moveSelectionPayload(
    selection: FileSelectionIntent,
    sourceParentId: number,
): FileMovePayloadSelection {
    if (selection.mode === 'all') {
        return {
            mode: 'all',
            source_parent_id: sourceParentId,
            excluded_ids: [...selection.excludedIds],
        };
    }

    return {
        mode: 'ids',
        ids: [...selection.ids],
    };
}

/**
 * Extract a validated move result from Inertia shared props.
 */
function extractMoveResult(props: unknown): FileMoveResult | null {
    if (!isRecord(props) || !isRecord(props.flash)) {
        return null;
    }

    const result = props.flash.moveResult;

    return isFileMoveResult(result) ? result : null;
}

/**
 * Create a user-facing success message from a move result.
 */
function moveSuccessMessage(result: FileMoveResult | null): string {
    if (!result) {
        return 'Items moved successfully.';
    }

    if (result.moved_count === 0) {
        return 'The selected items are already in this folder.';
    }

    const movedItems = `${result.moved_count} ${
        result.moved_count === 1 ? 'item' : 'items'
    } moved`;

    if (result.renamed_count === 0) {
        return `${movedItems} successfully.`;
    }

    return `${movedItems}. ${result.renamed_count} ${
        result.renamed_count === 1 ? 'item was' : 'items were'
    } automatically renamed to prevent conflicts.`;
}

/**
 * Select the most useful validation error from an Inertia response.
 */
function moveErrorMessage(errors: Record<string, string>): string {
    return (
        errors.move ??
        errors.target_parent_id ??
        errors.selection ??
        Object.values(errors)[0] ??
        'Unable to move the selected items. Please try again.'
    );
}

/**
 * Determine whether an unknown value is a move result.
 */
function isFileMoveResult(value: unknown): value is FileMoveResult {
    return (
        isRecord(value) &&
        typeof value.moved_count === 'number' &&
        typeof value.renamed_count === 'number' &&
        Array.isArray(value.source_parent_ids) &&
        typeof value.target_parent_id === 'number' &&
        Array.isArray(value.renamed_items)
    );
}

/**
 * Determine whether an unknown value is an object with string keys.
 */
function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}
