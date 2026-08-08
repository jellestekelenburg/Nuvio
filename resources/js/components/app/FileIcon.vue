<script setup lang="ts">
import { computed } from 'vue';

type FileLike = {
    is_folder?: boolean;
    name?: string;
    extension?: string | null;
    mime?: string | null;
};

const props = defineProps<{
    file: FileLike;
}>();

const EXT_GROUPS: Record<string, string[]> = {
    image: [
        'png',
        'jpg',
        'jpeg',
        'svg',
        'webp',
        'tif',
        'tiff',
        'gif',
        'bmp',
        'heic',
    ],
    video: ['mp4', 'mov', 'mkv', 'webm', 'avi', 'm4v'],
    audio: ['mp3', 'wav', 'flac', 'aac', 'm4a', 'ogg'],
    pdf: ['pdf'],
    zip: ['zip', 'rar', '7z', 'tar', 'gz'],
};

const ICON_PATHS: Record<string, string> = {
    image: 'M304 112L176 112L176 528L464 528L464 272L304 272L304 112zM444.1 224L352 131.9L352 224L444.1 224zM176 64L352 64L512 224L512 576L128 576L128 64L176 64zM416 496L224 496L224 448L320 352L416 448L416 496zM240 288C257.7 288 272 302.3 272 320C272 337.7 257.7 352 240 352C222.3 352 208 337.7 208 320C208 302.3 222.3 288 240 288z',
    video: 'M176 112L304 112L304 272L464 272L464 528L176 528L176 112zM352 131.9L444.1 224L352 224L352 131.9zM352 64L128 64L128 576L512 576L512 224L352 64zM208 320L208 480L368 480L368 424L432 464L432 336L368 376L368 320L208 320z',
    audio: 'M176 112L304 112L304 272L464 272L464 528L176 528L176 112zM352 131.9L444.1 224L352 224L352 131.9zM352 64L128 64L128 576L512 576L512 224L352 64zM336 495.3C385.3 491.2 424 450 424 399.6C424 349.2 385.3 308 336 303.9L336 344.1C363.1 348 384 371.3 384 399.5C384 427.7 363.1 451.1 336 454.9L336 495.1zM336 434.7C352 431.1 364 416.7 364 399.6C364 382.5 352 368.1 336 364.5L336 434.7zM248 359.6L208 359.6L208 439.6L248 439.6L280 479.6L304 479.6L304 319.6L280 319.6L248 359.6z',
    pdf: 'M240 112L112 112L112 528L208 528L208 576L64 576L64 64L288 64L448 224L448 400L400 400L400 272L240 272L240 112zM380.1 224L288 131.9L288 224L380.1 224zM272 444L304 444C337.1 444 364 470.9 364 504C364 537.1 337.1 564 304 564L292 564L292 612L252 612L252 444L272 444zM304 524C315 524 324 515 324 504C324 493 315 484 304 484L292 484L292 524L304 524zM400 444L432 444C460.7 444 484 467.3 484 496L484 560C484 588.7 460.7 612 432 612L380 612L380 444L400 444zM432 572C438.6 572 444 566.6 444 560L444 496C444 489.4 438.6 484 432 484L420 484L420 572L432 572zM508 444L596 444L596 484L548 484L548 508L596 508L596 548L548 548L548 612L508 612L508 444z',
    zip: 'M240 112L112 112L112 528L272 528L272 576L64 576L64 64L288 64L448 224L448 400L400 400L400 272L240 272L240 112zM380.1 224L288 131.9L288 224L380.1 224zM336 444L420 444L420 477.3L417.4 481.9L365.9 572L420 572L420 612L316 612L316 578.7L318.6 574.1L370.1 484L316 484L316 444L336 444zM484 464L484 612L444 612L444 444L484 444L484 464zM508 444L560 444C593.1 444 620 470.9 620 504C620 537.1 593.1 564 560 564L548 564L548 612L508 612L508 444zM548 524L560 524C571 524 580 515 580 504C580 493 571 484 560 484L548 484L548 524z',
    file: 'M176 112L304 112L304 272L464 272L464 528L176 528L176 112zM352 131.9L444.1 224L352 224L352 131.9zM352 64L128 64L128 576L512 576L512 224L352 64zM248 320L224 320L224 368L416 368L416 320L248 320zM248 416L224 416L224 464L416 464L416 416L248 416z',
};

function getExtension(file: FileLike): string {
    // Prefer an explicit extension field, otherwise derive from name.
    const raw = (file.extension ?? '') || '';
    if (raw) return raw.replace(/^\./, '').toLowerCase();

    const name = file.name ?? '';
    const lastDot = name.lastIndexOf('.');
    if (lastDot === -1) return '';
    return name.slice(lastDot + 1).toLowerCase();
}

const iconKey = computed(() => {
    if (props.file?.is_folder) return 'folder';

    const ext = getExtension(props.file);
    if (!ext) return 'file';

    for (const [key, exts] of Object.entries(EXT_GROUPS)) {
        if (exts.includes(ext)) return key;
    }

    return 'file';
});

const activePaths = computed<string>(() => {
    const key = iconKey.value;
    if (key === 'folder') return '';
    return ICON_PATHS[key] ?? ICON_PATHS.file;
});
</script>

<template>
    <span class="inline-block">
        <svg
            v-if="props.file.is_folder"
            class="size-6 fill-file-icon"
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 640 640"
        >
            <path
                d="M352 144L288 96L64 96L64 512L576 512L576 144L352 144zM528 192L528 464L112 464L112 144L272 144C312.5 174.4 333.9 190.4 336 192L528 192z"
            />
        </svg>

        <svg
            v-else
            class="size-6 fill-file-icon"
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 640 640"
            aria-hidden="true"
        >
            <path :d="activePaths"></path>
        </svg>
    </span>
</template>

<style scoped></style>
