<script setup lang="ts">
import { AlertCircle, FolderInput, LoaderCircle } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FolderPicker from '@/components/app/FolderPicker.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { FileSelectionIntent } from '@/composables/useFileSelection';
import { useMoveFiles } from '@/composables/useMoveFiles';
import type { FileMoveResult } from '@/types/file-move';

type MovableItem = {
    id: number;
    is_folder: boolean;
};

const props = defineProps<{
    modelValue: boolean;
    selection: FileSelectionIntent;
    selectedItems: readonly MovableItem[];
    selectedCount: number;
    sourceFolderId: number;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
    moved: [result: FileMoveResult | null];
}>();

const targetFolderId = ref<number | null>(null);
const { processing, error, submit, reset } = useMoveFiles();

const disabledFolderIds = computed<number[]>(() =>
    props.selectedItems
        .filter((item) => item.is_folder)
        .map((folder) => folder.id),
);

const canSubmit = computed<boolean>(
    () =>
        props.selectedCount > 0 &&
        targetFolderId.value !== null &&
        targetFolderId.value !== props.sourceFolderId &&
        !processing.value,
);

const itemLabel = computed<string>(() =>
    props.selectedCount === 1 ? 'item' : 'items',
);

/**
 * Update the controlled dialog state when closing is allowed.
 */
function updateOpen(open: boolean): void {
    if (!open && processing.value) {
        return;
    }

    emit('update:modelValue', open);
}

/**
 * Submit the selected items to the chosen destination.
 */
function moveSelectedItems(): void {
    if (!canSubmit.value || targetFolderId.value === null) {
        return;
    }

    submit({
        selection: props.selection,
        sourceParentId: props.sourceFolderId,
        targetParentId: targetFolderId.value,
        onSuccess: (result) => {
            emit('moved', result);
            emit('update:modelValue', false);
        },
    });
}

watch(
    () => props.modelValue,
    (open) => {
        if (open) {
            targetFolderId.value = null;
            reset();
        }
    },
);
</script>

<template>
    <Dialog :open="modelValue" @update:open="updateOpen">
        <DialogContent
            class="rounded-xl sm:max-w-xl"
            :show-close-button="!processing"
        >
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <FolderInput class="size-5" />
                    Move {{ selectedCount }} {{ itemLabel }}
                </DialogTitle>

                <DialogDescription>
                    Choose a destination folder for the selected
                    {{ itemLabel }}.
                </DialogDescription>
            </DialogHeader>

            <FolderPicker
                v-if="modelValue"
                v-model="targetFolderId"
                :initial-folder-id="sourceFolderId"
                :disabled-folder-ids="disabledFolderIds"
            />

            <Alert v-if="error" variant="destructive">
                <AlertCircle class="size-4" />
                <AlertTitle>Unable to move items</AlertTitle>
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>

            <p
                v-if="targetFolderId === sourceFolderId"
                class="text-sm text-muted-foreground"
            >
                Choose a different folder to move these items.
            </p>

            <DialogFooter>
                <Button
                    type="button"
                    variant="secondary"
                    :disabled="processing"
                    @click="updateOpen(false)"
                >
                    Cancel
                </Button>

                <Button
                    type="button"
                    :disabled="!canSubmit"
                    @click="moveSelectedItems"
                >
                    <LoaderCircle
                        v-if="processing"
                        class="size-4 animate-spin"
                    />
                    {{ processing ? 'Moving items' : 'Move here' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
