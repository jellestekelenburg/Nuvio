<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import FormProgress from '@/components/app/FormProgress.vue';
import Notification from '@/components/app/global/Notification.vue';
import { SidebarInset } from '@/components/ui/sidebar';
import {
    emitter,
    FILE_UPLOAD_STARTED,
    showErrorNotification,
} from '@/composables/event-bus';
import { uploadErrorMessage } from '@/lib/uploads/errors';
import type { UploadQueueItem } from '@/lib/uploads/types';
import { uploadSelection } from '@/lib/uploads/uploadOrchestrator';

type Props = {
    variant?: 'header' | 'sidebar';
    class?: string;
};

const dragOver = ref(false);
const page = usePage();
const fileUploadForm = useForm<{
    files: File[];
    relative_paths: string[];
    parent_id: number | null;
}>({
    files: [],
    relative_paths: [],
    parent_id: null,
});
const currentFolderId = computed<number | null>(() => {
    const folder = page.props.folder as
        | { id?: number; data?: { id?: number } }
        | undefined;

    return folder?.id ?? folder?.data?.id ?? null;
});

const props = defineProps<Props>();
const className = computed(() => props.class);

function onDragOver() {
    dragOver.value = true;
}
function onDragLeave() {
    dragOver.value = false;
}
function handleDrop(ev: DragEvent) {
    dragOver.value = false;
    const files = ev.dataTransfer?.files;

    if (!files?.length) {
        return;
    }

    uploadFiles(files);
}

async function uploadFiles(files: FileList | File[]) {
    try {
        await uploadSelection({
            files,
            parentId: currentFolderId.value,
            onQueueCreated: syncUploadForm,
        });
    } catch (error) {
        handleError(error);
    }
}

function syncUploadForm(uploadItems: UploadQueueItem[]) {
    fileUploadForm.parent_id = currentFolderId.value;
    fileUploadForm.files = uploadItems.map((item) => item.file);
    fileUploadForm.relative_paths = uploadItems.map(
        (item) => item.relative_path,
    );
}

function handleError(error: unknown) {
    showErrorNotification(uploadErrorMessage(error));
}

function handleUploadStarted(files: unknown) {
    if (files instanceof FileList || Array.isArray(files)) {
        void uploadFiles(files);
    }
}

onMounted(() => {
    emitter.on(FILE_UPLOAD_STARTED, handleUploadStarted);
});

onUnmounted(() => {
    emitter.off(FILE_UPLOAD_STARTED, handleUploadStarted);
});
</script>

<template>
    <SidebarInset
        :class="[className, dragOver ? 'justify-center' : '']"
        @drop.prevent="handleDrop"
        @dragover.prevent="onDragOver"
        @dragleave.prevent="onDragLeave"
    >
        <template v-if="dragOver">
            <div class="flex w-full items-center justify-center">
                <div
                    class="rounded-xl border-2 border-dotted border-gray-300 p-12 text-gray-700 lg:p-16"
                >
                    Drop Files here to upload!
                </div>
            </div>
        </template>
        <template v-else>
            <slot />
            <FormProgress :form="fileUploadForm" />
            <Notification />
        </template>
    </SidebarInset>
</template>
