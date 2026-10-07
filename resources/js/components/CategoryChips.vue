<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    // [{ key, label }] of the categories that have stories.
    categories: { type: Array, required: true },
    // Key of the category being shown, or null for "All".
    active: { type: String, default: null },
});

const chipClass = (isActive) => [
    'inline-flex rounded-full border px-3.5 py-1.5 text-sm font-medium transition outline-none focus-visible:ring-2 focus-visible:ring-speaking/40',
    isActive ? 'border-speaking/60 bg-speaking/10 text-speaking' : 'border-white/10 text-ink-300 hover:border-white/20 hover:text-ink-100',
];
</script>

<template>
    <nav aria-label="Filter by category">
        <ul class="flex flex-wrap gap-2">
            <li>
                <Link href="/feed" preserve-scroll :class="chipClass(active === null)" :aria-current="active === null ? 'page' : undefined">All</Link>
            </li>
            <li v-for="category in categories" :key="category.key">
                <Link
                    :href="`/feed?category=${category.key}`"
                    preserve-scroll
                    :class="chipClass(active === category.key)"
                    :aria-current="active === category.key ? 'page' : undefined"
                >
                    {{ category.label }}
                </Link>
            </li>
        </ul>
    </nav>
</template>
