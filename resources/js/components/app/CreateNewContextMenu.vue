<script setup lang="ts">
import {
    ContextMenuRoot,
    ContextMenuTrigger,
    ContextMenuContent,
    ContextMenuItem,
} from 'reka-ui';
import { ref, watch } from 'vue';
import CreateNewMenuContent from './CreateNewMenuContent.vue';

const emit = defineEmits<{ (e: 'create-folder'): void }>();

const isOpen = ref(false);
const shouldCreateFolder = ref(false);

function handleCreateFolderSelect() {
    shouldCreateFolder.value = true;
}

watch(isOpen, (open) => {
    if (!open && shouldCreateFolder.value) {
        shouldCreateFolder.value = false;
        requestAnimationFrame(() => {
            emit('create-folder');
        });
    }
});
</script>

<template>
    <ContextMenuRoot v-model:open="isOpen">
        <ContextMenuTrigger as-child>
            <div class="h-full">
                <slot />
            </div>
        </ContextMenuTrigger>

        <ContextMenuContent
            class="w-42 rounded-md border bg-white p-1 shadow-lg"
            @contextmenu.stop.prevent
        >
            <CreateNewMenuContent
                :item-component="ContextMenuItem"
                @create-folder-select="handleCreateFolderSelect"
            />
        </ContextMenuContent>
    </ContextMenuRoot>
</template>
