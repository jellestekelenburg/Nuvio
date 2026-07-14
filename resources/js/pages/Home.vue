<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { animate, scroll } from 'motion';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import Blur from '@/components/ui/home/Blur.vue';
import { login, myFiles, register } from '@/routes';

const heroSection = ref<HTMLElement | null>(null);
const heroImage = ref<HTMLElement | null>(null);
const heroPattern = ref<HTMLElement | null>(null);
const stopScrollAnimations: Array<() => void> = [];

onMounted(() => {
    if (
        !heroSection.value ||
        !heroImage.value ||
        !heroPattern.value ||
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    ) {
        return;
    }

    const scrollOptions = {
        target: heroSection.value,
        offset: ['start start', 'end start'] as const,
    };

    stopScrollAnimations.push(
        scroll(
            animate(
                heroImage.value,
                {
                    transform: [
                        'translateY(0px) scale(1)',
                        'translateY(110px) scale(0.965)',
                    ],
                    opacity: [1, 0.8],
                },
                { ease: 'linear' },
            ),
            scrollOptions,
        ),
        scroll(
            animate(
                heroPattern.value,
                {
                    transform: ['translateY(0px)', 'translateY(-52px)'],
                },
                { ease: 'linear' },
            ),
            scrollOptions,
        ),
    );
});

onBeforeUnmount(() => {
    stopScrollAnimations.splice(0).forEach((stop) => stop());
});

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);
</script>

<template>
    <Head title="Give your files a safe home">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>

    <Blur />

    <header
        class="relative z-50 mx-auto mb-6 flex w-full max-w-6xl justify-between py-6 text-sm not-has-[nav]:hidden"
    >
        <Link href="/" class="flex items-center gap-x-2 dark:text-white">
            <AppLogo />
        </Link>
        <nav class="flex items-center justify-end gap-x-7">
            <Link class="transition-all hover:-translate-y-px">Pricing</Link>
            <Link class="transition-all hover:-translate-y-px">Faq</Link>
            <Link class="transition-all hover:-translate-y-px">Support</Link>
            <Link
                v-if="$page.props.auth.user"
                :href="myFiles()"
                class="inline-flex rounded-lg bg-orange-600 px-5 py-2 font-semibold text-white transition-colors hover:bg-orange-500"
            >
                My Cloud
            </Link>
            <template v-else>
                <div class="inline-flex gap-2.5">
                    <Link
                        :href="login()"
                        class="inline-flex rounded-lg border border-gray-400 bg-transparent px-5 py-2 font-semibold text-zinc-800 transition-colors hover:bg-zinc-100"
                    >
                        Log in
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="register()"
                        class="inline-flex rounded-lg bg-orange-600 px-5 py-2 font-semibold text-white transition-colors hover:bg-orange-500"
                    >
                        Register
                    </Link>
                </div>
            </template>
        </nav>
    </header>

    <section ref="heroSection" class="hero relative overflow-hidden">
        <div class="mx-auto max-w-6xl py-24">
            <div class="text-center">
                <h1 class="font-serif text-7xl">
                    Give your files a safe home.
                </h1>
                <p class="my-3 text-zinc-600">
                    Store, organize, and share your files securely from
                    anywhere.
                </p>
                <div v-if="$page.props.auth.user">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="myFiles()"
                        class="inline-flex rounded-lg bg-orange-600 px-5 py-2 font-semibold text-white transition-colors hover:bg-orange-500"
                    >
                        Go to my cloud
                    </Link>
                </div>
                <div v-else>
                    <div class="inline-flex gap-2.5">
                        <Link
                            :href="login()"
                            class="inline-flex rounded-lg border border-gray-400 bg-transparent px-5 py-2 font-semibold text-zinc-800 transition-colors hover:bg-zinc-100"
                        >
                            Log in
                        </Link>
                        <Link
                            v-if="canRegister"
                            :href="register()"
                            class="inline-flex rounded-lg bg-orange-600 px-5 py-2 font-semibold text-white transition-colors hover:bg-orange-500"
                        >
                            Register
                        </Link>
                    </div>
                </div>
            </div>

            <div
                ref="heroImage"
                class="relative z-1 mt-10 flex w-full rounded-xl bg-white p-1 will-change-transform"
            >
                <img
                    src="/img/hero.webp"
                    alt="The Nuvio cloud file manager"
                    class="rounded-lg"
                />
            </div>

            <div
                aria-hidden="true"
                class="pointer-events-none absolute bottom-0 left-0 z-2 h-100 w-full"
            >
                <div
                    ref="heroPattern"
                    class="absolute inset-0 z-1 bg-repeat-x will-change-transform"
                    style="
                        background-image: url('/img/pattern/top.svg');
                        background-position-y: bottom;
                    "
                />
                <div
                    class="absolute bottom-0 left-0 h-13 w-full bg-[#914b09]"
                />
            </div>
        </div>
    </section>

    <section class="bg-[#914b09] text-zinc-50">
        <div class="mx-auto max-w-6xl space-y-20 py-20">
            <div
                class="grid grid-cols-[1.25fr_1fr] items-center gap-x-16 gap-y-6"
            >
                <div>
                    <h3 class="font-serif text-6xl">
                        Start free. Grow when you’re ready.
                    </h3>
                    <p class="mt-3 text-zinc-100">
                        Your first 5 GB is free. Need more room? Add storage
                        whenever you like and only pay for what you use.
                    </p>
                    <Link
                        v-if="canRegister"
                        :href="register()"
                        class="mt-4 inline-flex rounded-lg bg-orange-600 px-5 py-2 font-semibold text-white transition-colors hover:bg-orange-500"
                    >
                        Create account
                    </Link>
                </div>

                <img
                    src="/img/storage.webp"
                    class="mt-6 w-full rounded-lg"
                    alt="Get 5 gigabyte for free"
                />
            </div>

            <div
                class="grid grid-cols-[1fr_1.25fr] items-center gap-x-16 gap-y-6"
            >
                <img
                    src="/img/storage.webp"
                    class="mt-6 w-full rounded-lg"
                    alt="Get 5 gigabyte for free"
                />
                <div>
                    <h3 class="font-serif text-6xl">
                        Everything worth keeping, kept close.
                    </h3>
                    <p class="mt-3 max-w-xl text-zinc-100">
                        From everyday documents to your favorite memories—keep
                        it all safe, organized, and ready when you need it.
                    </p>
                    <Link
                        v-if="canRegister"
                        :href="'/'"
                        class="mt-4 inline-flex items-center gap-2 font-semibold text-zinc-100 transition-all hover:translate-x-1"
                    >
                        Most asked questions
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 640 640"
                            class="size-4 fill-current"
                        >
                            <path
                                d="M601.4 337L618.4 320L601.4 303L465.4 167L448.4 150L414.5 183.9L431.5 200.9L526.5 295.9L32.4 295.9L32.4 343.9L526.5 343.9L431.5 438.9L414.5 455.9L448.4 489.8L465.4 472.8L601.4 336.8z"
                            />
                        </svg>
                    </Link>
                </div>
            </div>
        </div>
    </section>
</template>
