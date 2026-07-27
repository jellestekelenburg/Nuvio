<script setup lang="ts">
import { FolderInput } from '@lucide/vue';
import { computed, ref } from 'vue';
import MoveFilesDialog from '@/components/app/MoveFilesDialog.vue';
import { Button } from '@/components/ui/button';
import type { FileSelectionIntent } from '@/composables/useFileSelection';
import type { FileMoveResult } from '@/types/file-move';

type MovableItem = {
    id: number;
    is_folder: boolean;
};

const props = defineProps<{
    selection: FileSelectionIntent;
    selectedItems: readonly MovableItem[];
    selectedCount: number;
    sourceFolderId: number | null;
}>();

const emit = defineEmits<{
    moved: [result: FileMoveResult | null];
}>();

const dialogOpen = ref(false);

const canOpen = computed<boolean>(
    () => props.selectedCount > 0 && props.sourceFolderId !== null,
);

/**
 * Open the move dialog when a valid selection and source folder exist.
 */
function openDialog(): void {
    if (!canOpen.value) {
        return;
    }

    dialogOpen.value = true;
}

/**
 * Forward a completed move to the file-list owner.
 */
function handleMoved(result: FileMoveResult | null): void {
    emit('moved', result);
}
</script>

<template>
    <Button v-if="canOpen" type="button" class="h-9" @click="openDialog">
        <FolderInput class="size-4" />
        Move to…
    </Button>

    <MoveFilesDialog
        v-if="sourceFolderId !== null"
        v-model="dialogOpen"
        :selection="selection"
        :selected-items="selectedItems"
        :selected-count="selectedCount"
        :source-folder-id="sourceFolderId"
        @moved="handleMoved"
    />
</template>
