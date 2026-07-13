<script setup lang="ts">
import { computed } from 'vue';
import type {
    UploadPlanResponse,
    UploadQueueItem,
    UploadStatus,
} from '@/lib/uploads/types';

const props = defineProps<{
    show: boolean;
    state: 'idle' | 'planning' | 'uploading' | 'completed' | 'failed';
    queue: UploadQueueItem[];
    plan: UploadPlanResponse | null;
    startedAt: string | null;
    finishedAt: string | null;
}>();

const summary = computed(() => {
    const totalFiles = props.queue.length;
    const totalBytes = props.queue.reduce((sum, item) => sum + item.size, 0);
    const averageProgress =
        totalFiles > 0
            ? Math.round(
                  props.queue.reduce((sum, item) => sum + item.progress, 0) /
                      totalFiles,
              )
            : 0;
    const counts = props.queue.reduce(
        (result, item) => {
            result[item.status] += 1;

            return result;
        },
        {
            queued: 0,
            planning: 0,
            uploading: 0,
            done: 0,
            failed: 0,
        } satisfies Record<UploadStatus, number>,
    );

    return {
        state: props.state,
        total_files: totalFiles,
        total_bytes: totalBytes,
        total_size: formatBytes(totalBytes),
        average_progress: averageProgress,
        counts,
        started_at: props.startedAt,
        finished_at: props.finishedAt,
    };
});

function formatBytes(bytes: number): string {
    if (bytes === 0) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const exponent = Math.min(
        Math.floor(Math.log(bytes) / Math.log(1024)),
        units.length - 1,
    );
    const value = bytes / 1024 ** exponent;

    return `${value.toFixed(value >= 10 || exponent === 0 ? 0 : 1)} ${units[exponent]}`;
}
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-99999 flex h-screen w-screen flex-col items-center justify-center bg-black/80 p-6"
    >
        <div
            class="flex w-160 max-w-full flex-col rounded-xl border border-border bg-white p-6 text-zinc-950"
        >
            <h4 class="text-xl font-bold text-zinc-800">Upload progress</h4>
            <div class="flex flex-wrap justify-between gap-x-6">
                <p class="text-sm text-zinc-500">
                    Please wait until the upload is completed
                </p>
                <p class="text-sm text-zinc-500">
                    Uploading {{ summary.total_files }}
                    {{ summary.total_files > 1 ? 'files' : 'file' }}
                </p>
            </div>

            <div
                class="upload-bar relative my-3 h-1.5 w-full overflow-hidden rounded-full"
            >
                <div
                    class="absolute top-0 left-0 z-1 h-1.5 rounded-full bg-blue-500 transition-all duration-500 ease-in-out"
                    :style="{ width: `${summary.average_progress}%` }"
                ></div>
            </div>
            <div class="flex justify-end gap-2 text-xs text-zinc-400">
                <span>{{ summary.total_size }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
@reference '#app.css';

.upload-bar {
    background: var(--color-gray-200);
    background-image: linear-gradient(
        90deg,
        var(--color-gray-100) 0%,
        var(--color-gray-300) 50%,
        var(--color-gray-100) 100%
    );
    background-size: 200% 100%;
    animation: animateBar 2s ease-in-out infinite;
}

@keyframes animateBar {
    from {
        background-position-x: 0;
    }
    to {
        background-position-x: 200%;
    }
}
</style>
