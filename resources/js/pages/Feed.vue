<script setup>
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import ArticleCard from '../components/ArticleCard.vue';
import AppLayout from '../layouts/AppLayout.vue';
import { dayLabel } from '../lib/time';

defineOptions({ layout: AppLayout });

const props = defineProps({
    articles: { type: Array, required: true },
    // { [articleId]: debateId } — an empty PHP collection is serialised as [].
    activeDebates: { type: [Object, Array], required: true },
});

const today = new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' });

// Articles arrive newest first; group them under "Today", "Yesterday", …
const days = computed(() => {
    const groups = new Map();

    for (const article of props.articles) {
        const label = dayLabel(article.published_at);
        groups.set(label, [...(groups.get(label) ?? []), article]);
    }

    return [...groups].map(([label, articles]) => ({ label, articles }));
});
</script>

<template>
    <Head title="Today's news" />

    <div class="mx-auto max-w-3xl">
        <header>
            <p class="text-sm font-medium text-ink-400">{{ today }}</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink-100 sm:text-4xl">Pick a story. Take a side.</h1>
            <p class="mt-3 max-w-xl text-ink-400">Read the summary, steal the vocabulary, then defend your opinion out loud.</p>
        </header>

        <div v-if="articles.length === 0" class="mt-12 rounded-2xl border border-dashed border-white/10 p-10 text-center">
            <p class="font-medium text-ink-200">No news harvested yet</p>
            <p class="mt-2 text-sm text-ink-400">
                The harvester runs every day at 03:00. To fetch stories now, run
                <code class="rounded bg-white/5 px-1.5 py-0.5 text-ink-200">sail artisan news:fetch</code>.
            </p>
        </div>

        <section v-for="day in days" :key="day.label" class="mt-12">
            <h2 class="mb-4 flex items-center gap-3 text-xs font-semibold tracking-wider text-ink-500 uppercase">
                {{ day.label }}
                <span class="h-px flex-1 bg-white/5" />
            </h2>

            <div class="space-y-4">
                <ArticleCard v-for="article in day.articles" :key="article.id" :article="article" :active-debate-id="activeDebates[article.id] ?? null" />
            </div>
        </section>
    </div>
</template>
