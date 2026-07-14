<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import FormProgress from '@/components/app/FormProgress.vue';
import Notification from '@/components/app/global/Notification.vue';
import UploadSummary from '@/components/app/UploadSummary.vue';
import { SidebarInset } from '@/components/ui/sidebar';
import {
    emitter,
    FILE_UPLOAD_STARTED,
    showErrorNotification,
    showSuccessNotification,
} from '@/composables/event-bus';
import { uploadErrorMessage } from '@/lib/uploads/errors';
import type { UploadPlanResponse, UploadQueueItem } from '@/lib/uploads/types';
import { uploadSelection } from '@/lib/uploads/uploadOrchestrator';

type Props = {
    variant?: 'header' | 'sidebar';
    class?: string;
};

const dragOver = ref(false);
const uploadQueue = ref<UploadQueueItem[]>([]);
const uploadPlan = ref<UploadPlanResponse | null>(null);
const uploadState = ref<
    'idle' | 'planning' | 'uploading' | 'completed' | 'failed'
>('idle');
const uploadStartedAt = ref<string | null>(null);
const uploadFinishedAt = ref<string | null>(null);
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
const showUploadPanel = computed(
    () => uploadState.value !== 'idle' && uploadQueue.value.length > 0,
);

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
    uploadState.value = 'planning';
    uploadStartedAt.value = new Date().toISOString();
    uploadFinishedAt.value = null;
    uploadPlan.value = null;
    uploadQueue.value = [];

    try {
        await uploadSelection({
            files,
            parentId: currentFolderId.value,
            onQueueCreated: syncUploadForm,
            onQueueUpdated: syncUploadQueue,
            onPlanCreated: (plan) => {
                uploadPlan.value = plan;
                uploadState.value = plan.ok ? 'uploading' : 'failed';
            },
        });

        uploadState.value = 'completed';
        uploadFinishedAt.value = new Date().toISOString();
        showSuccessNotification('Upload completed successfully.');
        reloadFiles();
        window.setTimeout(resetUploadPanel, 1500);
    } catch (error) {
        uploadState.value = 'failed';
        uploadFinishedAt.value = new Date().toISOString();
        handleError(error);
        resetUploadPanel();
    }
}

function syncUploadForm(uploadItems: UploadQueueItem[]) {
    fileUploadForm.parent_id = currentFolderId.value;
    fileUploadForm.files = uploadItems.map((item) => item.file);
    fileUploadForm.relative_paths = uploadItems.map(
        (item) => item.relative_path,
    );
    syncUploadQueue(uploadItems);
}

function syncUploadQueue(uploadItems: UploadQueueItem[]) {
    uploadQueue.value = uploadItems.map((item) => ({ ...item }));
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

function reloadFiles() {
    router.reload({
        only: ['files', 'folder', 'ancestors'],
        preserveScroll: true,
    });
}

function resetUploadPanel() {
    uploadState.value = 'idle';
    uploadQueue.value = [];
    uploadPlan.value = null;
    uploadStartedAt.value = null;
    uploadFinishedAt.value = null;
}
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
            <UploadSummary
                :show="showUploadPanel"
                :state="uploadState"
                :queue="uploadQueue"
                :plan="uploadPlan"
                :started-at="uploadStartedAt"
                :finished-at="uploadFinishedAt"
            />
            <FormProgress :form="fileUploadForm" />
            <Notification />
        </template>
    </SidebarInset>
</template>
