<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle, Trash } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    showErrorNotification,
    showSuccessNotification,
} from '@/composables/event-bus';
import file from '@/routes/file';

const props = defineProps<{
    deleteAll?: boolean;
    deleteIds?: Array<number | string>;
}>();

const emit = defineEmits(['delete']);
const showModal = ref(false);
const form = useForm<{
    all: boolean;
    ids: Array<number | string>;
    parent_id: number | null;
}>({
    all: false,
    ids: [],
    parent_id: null,
});

function clickOnDelete() {
    if (!props.deleteAll && !(props.deleteIds?.length ?? 0)) {
        showErrorNotification(
            'Please select at least one file or folder to delete',
        );
        return;
    }
    showModal.value = true;
}
function triggerModal() {
    if (form.processing) {
        return;
    }

    showModal.value = !showModal.value;
}

function onDeleteConfirm() {
    if (form.processing) {
        return;
    }

    if (props.deleteAll) {
        form.all = true;
        form.ids = [];
    } else {
        form.all = false;
        form.ids = props.deleteIds ?? [];
    }

    form.delete(file.destroy().url, {
        onSuccess: () => {
            showModal.value = false;
            showSuccessNotification('Successfully deleted file(s)');
            emit('delete');
        },
    });
}
</script>

<template>
    <Button
        v-if="(props.deleteIds?.length ?? 0) > 0"
        type="button"
        variant="destructive"
        class="bg-destructive text-destructive-foreground hover:bg-destructive/90 dark:bg-destructive dark:hover:bg-destructive/90"
        @click="clickOnDelete"
    >
        <Trash />
        Delete
    </Button>

    <Dialog :open="showModal">
        <DialogContent
            class="sm:max-w-md"
            :show-close-button="!form.processing"
        >
            <DialogHeader>
                <DialogTitle
                    >Are you sure you want to permanently delete these
                    files?</DialogTitle
                >
            </DialogHeader>

            <DialogFooter>
                <Button
                    type="button"
                    variant="destructive"
                    :disabled="form.processing"
                    :aria-busy="form.processing"
                    @click="onDeleteConfirm"
                >
                    <LoaderCircle
                        v-if="form.processing"
                        class="size-4 animate-spin"
                    />
                    {{ form.processing ? 'Deleting...' : 'Yes, delete' }}
                </Button>
                <Button
                    type="button"
                    variant="secondary"
                    :disabled="form.processing"
                    @click="triggerModal"
                >
                    Close
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<style scoped></style>
