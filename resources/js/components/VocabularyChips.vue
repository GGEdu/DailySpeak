<script setup>
defineProps({
    words: { type: Array, required: true },
});
</script>

<template>
    <ul class="flex flex-wrap gap-1.5" aria-label="Key vocabulary">
        <li
            v-for="word in words"
            :key="word"
            class="rounded-md border border-thinking/20 bg-thinking/[0.07] px-2 py-0.5 text-xs font-medium text-[color-mix(in_oklab,var(--color-thinking)_70%,white)]"
        >
            {{ word }}
        </li>
    </ul>
</template>
