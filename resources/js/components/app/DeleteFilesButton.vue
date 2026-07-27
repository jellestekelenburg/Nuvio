<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
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
const page = usePage();
const deleteFilesForm = useForm<{
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
    showModal.value = !showModal.value;
}

function onDeleteConfirm() {
    deleteFilesForm.parent_id = page.props.folder?.id ?? null;
    if (props.deleteAll) {
        deleteFilesForm.all = true;
        deleteFilesForm.ids = [];
    } else {
        deleteFilesForm.all = false;
        deleteFilesForm.ids = props.deleteIds ?? [];
    }

    deleteFilesForm.delete(file.delete().url, {
        onSuccess: () => {
            showModal.value = false;
            emit('delete');
            showSuccessNotification('Successfully deleted file(s)');
        },
    });
}
</script>

<template>
    <button
        :class="(props.deleteIds?.length ?? 0) > 0 ? 'inline-flex' : 'hidden'"
        class="h-9 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium whitespace-nowrap text-primary-foreground transition-all outline-none hover:bg-primary/90 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 has-[>svg]:px-3 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4"
        @click="clickOnDelete"
    >
        <trash2 />
        Delete
    </button>

    <Dialog :open="showModal">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Are you sure?</DialogTitle>
            </DialogHeader>

            <DialogFooter>
                <Button @click="onDeleteConfirm" :variant="'destructive'"
                    >Yes, Delete</Button
                >
                <Button @click="triggerModal" :variant="'secondary'">
                    Close
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<style scoped></style>
