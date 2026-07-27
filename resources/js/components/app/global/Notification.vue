<script setup lang="ts">
import { Check, CircleAlert, X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { emitter, SHOW_NOTIFICATION } from '@/composables/event-bus';

const show = ref(false);
const type = ref<'success' | 'error' | ''>('success');
const message = ref('');
let timeout: ReturnType<typeof setTimeout> | undefined;

const icon = computed(() => (type.value === 'error' ? CircleAlert : Check));
const iconClass = computed(() =>
    type.value === 'error'
        ? 'bg-red-50 text-red-600 ring-red-100'
        : 'bg-emerald-50 text-emerald-600 ring-emerald-100',
);

function close() {
    show.value = false;

    if (timeout) {
        clearTimeout(timeout);
        timeout = undefined;
    }
}

onMounted(() => {
    emitter.on(SHOW_NOTIFICATION, ({ type: t, message: msg }) => {
        show.value = true;
        type.value = t;
        message.value = msg;

        if (timeout) clearTimeout(timeout);
        timeout = setTimeout(() => {
            close();
        }, 5000);
    });
});

onBeforeUnmount(() => {
    emitter.off(SHOW_NOTIFICATION);
    if (timeout) clearTimeout(timeout);
});
</script>

<template>
    <transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="translate-y-4 opacity-0 sm:translate-x-6 sm:translate-y-0 sm:scale-95"
        enter-to-class="translate-y-0 opacity-100 sm:translate-x-0 sm:scale-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="translate-y-0 opacity-100 sm:translate-x-0 sm:scale-100"
        leave-to-class="translate-y-3 opacity-0 sm:translate-x-6 sm:translate-y-0 sm:scale-95"
    >
        <div
            v-if="show"
            role="status"
            aria-live="polite"
            class="fixed right-4 bottom-4 left-4 z-999 flex items-start gap-3 rounded-xl border border-zinc-200/80 bg-white/95 p-4 pr-11 shadow-[0_12px_40px_-12px_rgba(0,0,0,0.28)] backdrop-blur-md sm:left-auto sm:w-96 dark:border-zinc-700 dark:bg-zinc-900/95"
        >
            <span
                :class="iconClass"
                class="flex size-9 shrink-0 items-center justify-center rounded-full ring-4"
            >
                <component :is="icon" class="size-4.5" stroke-width="2.5" />
            </span>

            <div class="min-w-0 pt-0.5">
                <p
                    class="text-sm font-semibold text-zinc-900 dark:text-zinc-50"
                >
                    {{ type === 'error' ? 'Something went wrong' : 'Success' }}
                </p>
                <p
                    class="mt-0.5 text-sm leading-5 text-zinc-600 dark:text-zinc-300"
                >
                    {{ message }}
                </p>
            </div>

            <button
                type="button"
                aria-label="Close notification"
                class="absolute top-3 right-3 rounded-md p-1.5 text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-700 focus-visible:ring-2 focus-visible:ring-zinc-400 focus-visible:outline-none dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                @click="close"
            >
                <X class="size-4" />
            </button>
        </div>
    </transition>
</template>
