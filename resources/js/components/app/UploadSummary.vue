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

const payload = computed(() => ({
    summary: summary.value,
    plan: props.plan,
    queue: props.queue.map((item) => ({
        client_id: item.client_id,
        name: item.name,
        size: item.size,
        size_formatted: formatBytes(item.size),
        relative_path: item.relative_path,
        content_type: item.content_type,
        last_modified: item.last_modified,
        status: item.status,
        progress: item.progress,
        error: item.error ?? null,
    })),
}));

const formattedPayload = computed(() => JSON.stringify(payload.value, null, 2));

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
    <div v-if="show" class="fixed left-1/2 top-1/2 bg-white w-120 h-120 overflow-hidden">
        <pre>{{ formattedPayload }}</pre>
    </div>
</template>
