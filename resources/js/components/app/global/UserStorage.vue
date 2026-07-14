<script setup lang="ts">
import axios from 'axios';
import { onMounted, onUnmounted, ref } from 'vue';
import { showErrorNotification } from '@/composables/event-bus';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        alwaysOpen?: boolean;
    }>(),
    {
        alwaysOpen: false,
    },
);

console.log(props.alwaysOpen);

type StorageData = {
    used_bytes: number;
    max_bytes: number;
    used_formatted: string;
    max_formatted: string;
    percentage: number;
    is_full: boolean;
};

let intervalId: ReturnType<typeof setInterval> | null = null;

const showStorageDetails = ref(false);
function storageShow() {
    showStorageDetails.value = true;
}
function storageHide() {
    showStorageDetails.value = false;
}

const storage = ref<StorageData | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
let isFetching = false;

const fetchData = async () => {
    if (isFetching) return;
    isFetching = true;

    try {
        loading.value = !storage.value;
        error.value = null;

        const response = await axios.get('/api/storage');
        storage.value = response.data;
    } catch (err) {
        error.value = 'Er ging iets mis bij het ophalen van data.';
        showErrorNotification(error.value);
        console.log(err);
    } finally {
        loading.value = false;
        isFetching = false;
    }
};

onMounted(() => {
    fetchData();

    intervalId = setInterval(() => {
        fetchData();
    }, 45000);
});
onUnmounted(() => {
    if (intervalId) {
        clearInterval(intervalId);
    }
});
</script>

<template>
    <div
        v-if="storage"
        class="my-2 inline-block max-w-60 overflow-hidden px-4 py-2 transition-all"
        @mouseenter="storageShow"
        @mouseleave="storageHide"
    >
        <p class="mb-1 text-sm">My cloud storage</p>
        <div class="h-1 w-full overflow-hidden rounded-full bg-gray-200">
            <div
                class="h-1"
                :style="{ width: storage.percentage + '%' }"
                :class="{
                    'bg-blue-500': storage.percentage < 80,
                    'bg-orange-400':
                        storage.percentage >= 80 && storage.percentage < 95,
                    'bg-red-500': storage.percentage >= 95,
                }"
            ></div>
        </div>
        <div
            class="relative z-0 flex justify-between text-xs transition-all"
            :class="
                cn(
                    !showStorageDetails
                        ? 'h-0 opacity-0'
                        : 'mt-1 h-4 opacity-100',
                    props.alwaysOpen && 'mt-1! h-4! opacity-100!',
                )
            "
        >
            <p>{{ storage.used_formatted }}</p>
            <p>{{ storage.max_formatted }}</p>
        </div>
    </div>
</template>
