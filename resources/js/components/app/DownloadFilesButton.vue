<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { DownloadCloudIcon } from '@lucide/vue';
import { ref } from 'vue';
import { showErrorNotification } from '@/composables/event-bus';
import { httpGet } from '@/composables/httpHelper';
import file from '@/routes/file';

const props = defineProps<{
    downloadAll?: boolean;
    downloadIds?: Array<number | string>;
}>();
const isLoading = ref(false);
const loadingAnimation = ref('.');

const page = usePage();

function downloadFiles() {
    if (isLoading.value) {
        return;
    }
    if (!props.downloadAll && !(props.downloadIds?.length ?? 0)) {
        showErrorNotification(
            'Please select at least one file or folder to download',
        );
        return;
    }

    isLoading.value = true;

    const p = new URLSearchParams();
    const parentId = page.props.folder?.id;

    p.append('parent_id', String(parentId));

    if (props.downloadAll) {
        p.append('all', '1');
    } else if (props.downloadIds) {
        for (const id of props.downloadIds) {
            p.append('ids[]', String(id));
        }
    }

    httpGet(file.download().url + '?' + p.toString())
        .then((response) => {
            if (response.message) {
                showErrorNotification(response.message);
                return;
            }

            if (!response.url) {
                return;
            }

            const a = document.createElement('a');
            a.download = response.filename;
            a.href = response.url;
            a.rel = 'noopener';
            a.click();
            isLoading.value = false;
        })
        .catch(() => {
            showErrorNotification(
                'Error during file download, please try again.',
            );
            isLoading.value = false;
        });
}

setInterval(() => {
    if (loadingAnimation.value.length < 3) {
        loadingAnimation.value += '.';
    } else {
        loadingAnimation.value = '.';
    }
}, 500);
</script>

<template>
    <button
        v-if="!isLoading"
        :class="(props.downloadIds?.length ?? 0) > 0 ? 'inline-flex' : 'hidden'"
        class="h-9 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium whitespace-nowrap text-primary-foreground transition-all outline-none hover:bg-primary/90 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 has-[>svg]:px-3 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4"
        @click="downloadFiles"
    >
        <download-cloud-icon />
        Download
    </button>
    <button
        v-else
        class="loading cursor-loading inline-flex h-9 shrink-0 items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium whitespace-nowrap text-primary-foreground transition-all outline-none hover:bg-primary/90 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 has-[>svg]:px-3 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4"
    >
        <download-cloud-icon />
        Downloading
        <span class="mr-1 -ml-1 w-2 text-start">{{ loadingAnimation }}</span>
    </button>
</template>

<style scoped>
@keyframes animateButton {
    0%,
    100% {
        opacity: 0.8;
    }
    50% {
        opacity: 0.6;
    }
}
button.loading {
    animation: animateButton 2s ease-in-out infinite;
}
</style>
