<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { isCurrentTab } from '../lib/tabs';

defineProps({
    // [{ label, href, external? }] from lib/tabs.js.
    tabs: { type: Array, required: true },
    // Names the group for screen readers, e.g. "Settings sections".
    label: { type: String, required: true },
});

const page = usePage();

const tabClass = (active) => [
    'inline-flex min-h-9 items-center rounded-lg px-3 py-1.5 text-sm font-medium transition outline-none focus-visible:ring-2 focus-visible:ring-speaking/40',
    active ? 'bg-white/10 text-ink-100' : 'text-ink-400 hover:bg-white/5 hover:text-ink-100',
];
</script>

<template>
    <nav :aria-label="label" class="mb-10 border-b border-white/8">
        <ul class="-mb-px flex flex-wrap gap-1">
            <li v-for="tab in tabs" :key="tab.href">
                <a v-if="tab.external" :href="tab.href" target="_blank" rel="noopener" :class="tabClass(false)">
                    {{ tab.label }}
                    <span class="sr-only">(opens in a new tab)</span>
                </a>
                <Link
                    v-else
                    :href="tab.href"
                    :class="tabClass(isCurrentTab(page.url, tab.href))"
                    :aria-current="isCurrentTab(page.url, tab.href) ? 'page' : undefined"
                >
                    {{ tab.label }}
                </Link>
            </li>
        </ul>
    </nav>
</template>
