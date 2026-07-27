<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Undo2 } from '@lucide/vue';
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
    restoreAll?: boolean;
    restoreIds?: Array<number | string>;
}>();

const emit = defineEmits(['restore']);
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

function clickOnrestore() {
    if (!props.restoreAll && !(props.restoreIds?.length ?? 0)) {
        showErrorNotification(
            'Please select at least one file or folder to restore',
        );
        return;
    }
    showModal.value = true;
}
function triggerModal() {
    showModal.value = !showModal.value;
}

function onrestoreConfirm() {
    if (props.restoreAll) {
        form.all = true;
        form.ids = [];
    } else {
        form.all = false;
        form.ids = props.restoreIds ?? [];
    }

    form.post(file.restore().url, {
        onSuccess: () => {
            showModal.value = false;
            showSuccessNotification('Successfully restored file(s)');
            emit('restore');
        },
    });
}
</script>

<template>
    <button
        :class="(props.restoreIds?.length ?? 0) > 0 ? 'inline-flex' : 'hidden'"
        class="h-9 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium whitespace-nowrap text-primary-foreground transition-all outline-none hover:bg-primary/90 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 has-[>svg]:px-3 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4"
        @click="clickOnrestore"
    >
        <Undo2 />
        Restore
    </button>

    <Dialog :open="showModal">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Are you sure?</DialogTitle>
            </DialogHeader>

            <DialogFooter>
                <Button @click="onrestoreConfirm">Yes, restore</Button>
                <Button @click="triggerModal" :variant="'secondary'">
                    Close
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<style scoped></style>
