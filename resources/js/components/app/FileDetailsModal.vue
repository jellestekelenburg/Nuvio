<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { File } from '@/lib/types.js';
import { formatDateTime } from '@/lib/utils';

defineProps<{
    modelValue: boolean;
    file: File | null;
}>();

const emit = defineEmits<{
    (event: 'update:modelValue', value: boolean): void;
}>();

function close() {
    emit('update:modelValue', false);
}
</script>

<template>
    <Dialog :open="modelValue" @update:open="emit('update:modelValue', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>
                    {{ file?.is_folder ? 'Folder' : 'File' }} details
                </DialogTitle>
                <DialogDescription>
                    Metadata for {{ file?.name }}
                </DialogDescription>
            </DialogHeader>
            <div>
                <div class="mt-2">
                    <div class="grid grid-cols-[8rem_auto]">
                        <div
                            class="flex flex-col rounded-sm bg-gray-100 text-end text-sm [&_p]:p-3 [&_p]:not-last:border-b"
                        >
                            <p>Name</p>
                            <p v-if="file?.type">File type</p>
                            <p v-if="file?.size && file?.size !== '-'">Size</p>
                            <p>Owner</p>
                            <p>Created date</p>
                            <p>Last updated</p>
                            <p>Created by</p>
                            <p>Last updated by</p>
                        </div>
                        <div
                            class="flex min-w-0 flex-col rounded-sm text-sm [&_p]:truncate [&_p]:p-3 [&_p]:not-last:border-b"
                        >
                            <p :title="file?.name">{{ file?.name }}</p>
                            <p v-if="file?.type">{{ file?.type }}</p>
                            <p v-if="file?.size && file?.size !== '-'">
                                {{ file?.size }}
                            </p>
                            <p>{{ file?.details.owner ?? '-' }}</p>
                            <p>
                                {{ formatDateTime(file?.details.created_at) }}
                            </p>
                            <p>
                                {{ formatDateTime(file?.details.updated_at) }}
                            </p>
                            <p>{{ file?.details.created_by ?? '-' }}</p>
                            <p>{{ file?.details.updated_by ?? '-' }}</p>
                        </div>
                    </div>
                </div>
            </div>
            <DialogFooter>
                <Button variant="secondary" @click="close">Close</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
