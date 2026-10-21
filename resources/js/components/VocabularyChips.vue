<script setup>
import { openLookup } from '../lib/lookup';

defineProps({
    words: { type: Array, required: true },
});
</script>

<template>
    <ul class="flex flex-wrap gap-1.5" aria-label="Key vocabulary">
        <li v-for="word in words" :key="word">
            <button
                type="button"
                class="inline-flex min-h-6 items-center rounded-md border border-thinking/20 bg-thinking/[0.07] px-2 py-1 text-xs font-medium text-[color-mix(in_oklab,var(--color-thinking)_70%,white)] transition hover:border-thinking/45 hover:bg-thinking/15"
                :aria-label="`Meaning of “${word}”`"
                @click="openLookup(word, null, $event.currentTarget)"
            >
                {{ word }}
            </button>
        </li>
    </ul>
</template>
