<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { showSuccessNotification } from '@/composables/event-bus';

type RenamableFile = { id: number; name: string; is_folder: boolean };

const props = defineProps<{
    modelValue: boolean;
    file: RenamableFile | null;
}>();
const emit = defineEmits<{
    (event: 'update:modelValue', value: boolean): void;
}>();
const input = ref<InstanceType<typeof Input> | null>(null);
const form = useForm({ name: '' });
const extension = computed(() => fileExtension(props.file));

function fileExtension(file: RenamableFile | null): string {
    if (!file || file.is_folder) return '';

    const lastDot = file.name.lastIndexOf('.');
    const isDotfile = file.name.startsWith('.');

    return lastDot > 0 && lastDot < file.name.length - 1 && !isDotfile
        ? file.name.slice(lastDot)
        : '';
}

const originalBaseName = computed(() => {
    if (!props.file) return '';
    return extension.value
        ? props.file.name.slice(0, -extension.value.length)
        : props.file.name;
});

watch(
    () => [props.modelValue, props.file] as const,
    ([open, file]) => {
        if (!open || !file) return;
        form.name = originalBaseName.value;
        form.clearErrors();
        nextTick(() => {
            const element = input.value?.$el as HTMLInputElement | undefined;
            element?.focus();
            element?.select();
        });
    },
);

function close() {
    emit('update:modelValue', false);
    form.clearErrors();
}

function rename() {
    if (!props.file || form.processing) return;

    form.transform((data) => ({
        name: `${data.name}${extension.value}`,
    })).patch(`/file/${props.file.id}/rename`, {
        preserveScroll: true,
        onSuccess: () => {
            close();
            showSuccessNotification('Renamed successfully.');
            router.reload({
                only: ['files', 'folder', 'ancestors'],
                preserveUrl: true,
                preserveScroll: true,
            });
        },
    });
}
</script>

<template>
    <Dialog
        :open="modelValue"
        @update:open="$emit('update:modelValue', $event)"
    >
        <DialogContent class="rounded-xl sm:max-w-md">
            <DialogHeader>
                <DialogTitle
                    >Rename
                    {{ file?.is_folder ? 'folder' : 'file' }}</DialogTitle
                >
                <DialogDescription>
                    Enter a new name. The file extension stays unchanged.
                </DialogDescription>
            </DialogHeader>
            <div class="mt-2">
                <div class="flex">
                    <Input
                        ref="input"
                        v-model="form.name"
                        aria-label="New name"
                        :aria-invalid="!!form.errors.name"
                        :class="[
                            form.errors.name ? 'border-red-500' : '',
                            extension ? 'rounded-r-none' : '',
                        ]"
                        @keyup.enter="rename"
                    />
                    <span
                        v-if="extension"
                        class="inline-flex items-center rounded-r-md border border-l-0 bg-gray-50 px-3 text-sm text-gray-500 dark:bg-gray-800 dark:text-gray-300"
                    >
                        {{ extension }}
                    </span>
                </div>
                <InputError :message="form.errors.name" class="mt-2" />
            </div>
            <DialogFooter>
                <Button variant="secondary" @click="close">Cancel</Button>
                <Button
                    :disabled="form.processing || !form.name.trim()"
                    @click="rename"
                >
                    Rename
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
