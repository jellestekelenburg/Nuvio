<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import folder from '@/routes/folder';

const props = defineProps<{
    modelValue: boolean;
}>();

const emit = defineEmits<{
    (event: 'update:modelValue', value: boolean): void;
}>();

const form = useForm({
    name: '',
    parent_id: null as number | null,
});
const page = usePage();

const currentFolderId = computed<number | null>(() => {
    const folder = page.props.folder as
        | { id?: number; data?: { id?: number } }
        | undefined;

    return folder?.id ?? folder?.data?.id ?? null;
});

function createFolder() {
    form.parent_id = currentFolderId.value;
    form.post(folder.create() as unknown as string, {
        preserveScroll: true,
        onSuccess: () => {
            closeModal();
            form.reset();
            router.reload({
                only: ['files', 'folder', 'ancestors'],
                preserveUrl: true,
            });
        },
    });
}
function closeModal() {
    emit('update:modelValue', false);
    form.clearErrors();
    form.reset();
}
</script>

<template>
    <Dialog
        :open="props.modelValue"
        @update:open="emit('update:modelValue', $event)"
    >
        <DialogContent class="rounded-xl sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="text-zinc-800"
                    >Create New Folder</DialogTitle
                >
            </DialogHeader>

            <div class="mt-2">
                <label for="folderName"></label>
                <Input
                    id="folderName"
                    v-model="form.name"
                    type="text"
                    autofocus
                    class="mt-1 block w-full"
                    :class="
                        form.errors.name
                            ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                            : ''
                    "
                    placeholder="Folder Name"
                    @keyup.enter="createFolder"
                />
                <InputError :message="form.errors.name" class="mt-2" />
            </div>

            <DialogFooter>
                <Button
                    @click="createFolder"
                    :variant="'default'"
                    :disabled="form.processing"
                    :class="{ 'opacity-25': form.processing }"
                    >Create folder</Button
                >
                <Button @click="closeModal" :variant="'secondary'">
                    Close
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
