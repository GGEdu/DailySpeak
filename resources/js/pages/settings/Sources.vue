<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import SectionTabs from '../../components/SectionTabs.vue';
import AppLayout from '../../layouts/AppLayout.vue';
import { groupByCategory } from '../../lib/sources.js';
import { SETTINGS_TABS } from '../../lib/tabs.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    // [{ id, name, category, hidden, policy_status }]: the catalogue, with the sources this user has hidden.
    sources: { type: Array, default: () => [] },
});

const groups = computed(() => groupByCategory(props.sources));

// Hiding a source only changes this user's feed; the catalogue and the other users are not touched.
const toggle = (source) => router.put(`/settings/sources/${source.id}`, { hidden: !source.hidden }, { preserveScroll: true });
</script>

<template>
    <Head title="Sources" />

    <div class="mx-auto max-w-3xl">
        <header>
            <h1 class="text-3xl font-semibold tracking-tight text-ink-100">Settings</h1>
        </header>

        <SectionTabs class="mt-8" :tabs="SETTINGS_TABS" label="Settings sections" />

        <p class="mt-10 max-w-prose text-ink-400">
            The news sources come from the catalogue the owner keeps. Switch off the ones you do not want in your feed: they
            disappear from your feed only, and you can switch them back on at any time.
        </p>

        <p v-if="sources.length === 0" class="mt-10 rounded-2xl border border-dashed border-white/10 p-8 text-center text-sm text-ink-400">
            The catalogue has no sources yet.
        </p>

        <section v-for="group in groups" :key="group.label" class="mt-10" :aria-labelledby="`category-${group.label}`">
            <h2 :id="`category-${group.label}`" class="mb-3 flex items-center gap-3 text-xs font-semibold tracking-wider text-ink-500 uppercase">
                {{ group.label }}
                <span class="h-px flex-1 bg-white/5" />
            </h2>

            <ul class="divide-y divide-white/5 rounded-2xl border border-white/8 bg-ink-900/60">
                <li v-for="source in group.sources" :key="source.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                    <div class="flex min-w-0 items-center gap-2">
                        <p class="truncate font-medium text-ink-100">{{ source.name }}</p>
                        <span
                            v-if="source.policy_status === 'warning'"
                            class="shrink-0 rounded-full bg-amber-400/10 px-2 py-0.5 text-[11px] font-medium text-amber-300"
                            title="Its usage terms need a look before you rely on it."
                        >
                            Usage terms
                        </span>
                    </div>

                    <button
                        type="button"
                        role="switch"
                        :aria-checked="String(!source.hidden)"
                        :aria-label="`Show ${source.name} in my feed`"
                        class="inline-flex min-h-6 shrink-0 items-center gap-3 rounded-lg text-sm text-ink-300 transition outline-none focus-visible:ring-2 focus-visible:ring-speaking/40"
                        @click="toggle(source)"
                    >
                        Show in my feed
                        <span
                            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border transition"
                            :class="source.hidden ? 'border-white/10 bg-ink-950/60' : 'border-speaking/60 bg-speaking/40'"
                            aria-hidden="true"
                        >
                            <span
                                class="inline-block h-4 w-4 transform rounded-full bg-ink-100 transition"
                                :class="source.hidden ? 'translate-x-1' : 'translate-x-6'"
                            />
                        </span>
                    </button>
                </li>
            </ul>
        </section>
    </div>
</template>
