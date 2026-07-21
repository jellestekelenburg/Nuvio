<script setup lang="ts">
import type * as events from 'node:events';
import { emitter, FILE_UPLOAD_STARTED } from '@/composables/event-bus';

const props = defineProps<{
    itemComponent: any;
}>();
function onChange(ev: events) {
    emitter.emit(FILE_UPLOAD_STARTED, ev.target.files);
}
</script>

<template>
    <component
        component
        :is="props.itemComponent"
        class="relative cursor-pointer rounded-sm border border-transparent p-2 text-sm hover:border-gray-300 hover:bg-gray-100"
    >
        Upload Files

        <input
            @change="onChange"
            type="file"
            class="absolute top-0 right-0 bottom-0 left-0 cursor-pointer opacity-0"
            multiple
        />
    </component>
</template>

<style scoped></style>
