<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle, Undo2 } from '@lucide/vue';
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
    if (form.processing) {
        return;
    }

    showModal.value = !showModal.value;
}

function onrestoreConfirm() {
    if (form.processing) {
        return;
    }

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
    <Button
        v-if="(props.restoreIds?.length ?? 0) > 0"
        type="button"
        @click="clickOnrestore"
    >
        <Undo2 />
        Restore
    </Button>

    <Dialog :open="showModal">
        <DialogContent
            class="sm:max-w-md"
            :show-close-button="!form.processing"
        >
            <DialogHeader>
                <DialogTitle>Are you sure?</DialogTitle>
            </DialogHeader>

            <DialogFooter>
                <Button
                    type="button"
                    :disabled="form.processing"
                    :aria-busy="form.processing"
                    @click="onrestoreConfirm"
                >
                    <LoaderCircle
                        v-if="form.processing"
                        class="size-4 animate-spin"
                    />
                    {{ form.processing ? 'Restoring...' : 'Yes, restore' }}
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
