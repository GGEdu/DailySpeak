<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    // [{ href, label }], from lib/aiErrors.js. Renders nothing for an empty list.
    links: { type: Array, default: () => [] },
});
</script>

<template>
    <span v-if="links.length > 0" class="flex flex-wrap gap-x-4 gap-y-1 text-sm font-medium">
        <Link
            v-for="link in links"
            :key="link.href"
            :href="link.href"
            class="inline-flex min-h-6 items-center text-ink-100 underline underline-offset-4 transition hover:text-white"
        >
            {{ link.label }}
        </Link>
    </span>
</template>
