<script setup lang="ts">
import { SquarePen, InfoIcon } from '@lucide/vue';
import {
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuRoot,
    ContextMenuTrigger,
} from 'reka-ui';
import { ref, watch } from 'vue';
import CreateNewMenuContent from './CreateNewMenuContent.vue';

const emit = defineEmits<{
    (event: 'rename'): void;
    (event: 'details'): void;
    (event: 'create-folder'): void;
}>();
const isOpen = ref(false);
const pendingAction = ref<'rename' | 'create-folder' | 'details' | null>(null);

watch(isOpen, (open) => {
    if (open || !pendingAction.value) return;

    const action = pendingAction.value;
    pendingAction.value = null;
    requestAnimationFrame(() => emit(action));
});
</script>

<template>
    <ContextMenuRoot v-model:open="isOpen">
        <ContextMenuTrigger as-child>
            <slot />
        </ContextMenuTrigger>
        <ContextMenuContent
            class="z-50 min-w-44 rounded-md border bg-white p-1 shadow-lg dark:border-gray-700 dark:bg-gray-800"
            @contextmenu.stop.prevent
        >
            <CreateNewMenuContent
                :item-component="ContextMenuItem"
                @create-folder-select="pendingAction = 'create-folder'"
            />

            <div
                class="my-1 border-b border-gray-200 dark:border-gray-700"
            ></div>

            <ContextMenuItem
                class="flex cursor-pointer items-center gap-2 rounded-sm border border-transparent p-2 text-sm outline-none hover:border-gray-300 hover:bg-gray-100 focus:bg-gray-100 dark:text-white dark:hover:bg-gray-700 dark:focus:bg-gray-700"
                @select="pendingAction = 'rename'"
            >
                <SquarePen class="size-4" />
                Rename
            </ContextMenuItem>

            <ContextMenuItem
                class="flex cursor-pointer items-center gap-2 rounded-sm border border-transparent p-2 text-sm outline-none hover:border-gray-300 hover:bg-gray-100 focus:bg-gray-100 dark:text-white dark:hover:bg-gray-700 dark:focus:bg-gray-700"
                @select="pendingAction = 'details'"
            >
                <InfoIcon class="size-4" />
                Details
            </ContextMenuItem>
        </ContextMenuContent>
    </ContextMenuRoot>
</template>
