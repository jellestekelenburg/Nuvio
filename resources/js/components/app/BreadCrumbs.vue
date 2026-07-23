<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { myFiles } from '@/routes';

type FileListItem = {
    id: number;
    name: string;
    parent_id: number | null;
    owner: string;
    updated_at: string | null;
    size: string | null;
    is_folder: boolean;
};

const props = withDefaults(
    defineProps<{
        ancestors?: { data: FileListItem[] };
    }>(),
    {
        ancestors: () => ({ data: [] }),
    },
);
</script>

<template>
    <nav class="my-4 flex items-center justify-between p-1">
        <ol class="inline-flex items-center space-x-1 md:space-x-2">
            <li
                v-for="ans of props.ancestors.data"
                :key="ans.id"
                class="inline-flex items-center"
            >
                <Link
                    v-if="!ans.parent_id"
                    :href="myFiles()"
                    class="inline-flex cursor-pointer items-center gap-x-1 text-sm font-medium whitespace-nowrap text-gray-700 hover:text-blue-600 dark:text-gray-400 dark:hover:text-white"
                >
                    <svg
                        class="size-4 fill-current"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 640 640"
                    >
                        <!--!Font Awesome Pro v7.2.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2026 Fonticons, Inc.-->
                        <path
                            d="M336 70.1C326.9 61.9 313.1 61.9 304 70.1L72 278.1C62.1 286.9 61.3 302.1 70.2 312C79.1 321.9 94.2 322.7 104.1 313.8L112.1 306.6L112.1 511.9C112.1 547.2 140.8 575.9 176.1 575.9L464.1 575.9C499.4 575.9 528.1 547.2 528.1 511.9L528.1 306.6L536.1 313.8C546 322.6 561.1 321.8 570 312C578.9 302.2 578 287 568.2 278.1L336 70.1zM480 263.7L480 512C480 520.8 472.8 528 464 528L176 528C167.2 528 160 520.8 160 512L160 263.7L320 120.2L480 263.7z"
                        />
                    </svg>
                    My files
                </Link>
                <div v-if="ans.parent_id" class="inline-flex items-center">
                    <svg
                        class="size-4 fill-current"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 640 640"
                    >
                        <!--!Font Awesome Pro v7.2.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2026 Fonticons, Inc.-->
                        <path
                            d="M433.5 303C442.9 312.4 442.9 327.6 433.5 336.9L273.5 497C264.1 506.4 248.9 506.4 239.6 497C230.3 487.6 230.2 472.4 239.6 463.1L382.6 320.1L239.6 177.1C230.2 167.7 230.2 152.5 239.6 143.2C249 133.9 264.2 133.8 273.5 143.2L433.5 303.2z"
                        />
                    </svg>
                    <Link
                        :href="myFiles({ folder: ans.id })"
                        class="ml-1 cursor-pointer text-sm font-medium text-gray-700 hover:text-blue-600 md:ml-2 dark:text-gray-400 dark:hover:text-white"
                    >
                        {{ ans.name }}
                    </Link>
                </div>
            </li>
        </ol>
    </nav>
</template>

<style scoped></style>
