<script setup lang="ts">
import FileUploadMenuItem from '@/components/app/FileUploadMenuItem.vue';
import FolderUploadMenuItem from '@/components/app/FolderUploadMenuItem.vue';

const props = defineProps<{
    itemComponent: any;
}>();

const emit = defineEmits<{
    (event: 'create-folder'): void;
    (event: 'close'): void;
    (event: 'create-folder-select'): void;
}>();
</script>

<template>
    <component
        :is="props.itemComponent"
        @select="emit('create-folder-select')"
        class="cursor-pointer rounded-sm border border-transparent p-2 text-sm hover:border-gray-300 hover:bg-gray-100"
    >
        Create Folder
    </component>

    <div class="my-1 border-b border-gray-200 dark:border-gray-700"></div>

    <FileUploadMenuItem :item-component="props.itemComponent" />
    <FolderUploadMenuItem :item-component="props.itemComponent" />
</template>

<style scoped></style>
