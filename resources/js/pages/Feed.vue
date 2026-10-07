<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ArticleCard from '../components/ArticleCard.vue';
import CategoryChips from '../components/CategoryChips.vue';
import AppLayout from '../layouts/AppLayout.vue';
import { dayLabel } from '../lib/time';

defineOptions({ layout: AppLayout });

const props = defineProps({
    // The current search ('' shows the latest news).
    search: { type: String, required: true },
    // Key of the category shown, or null for all of them (ignored while searching).
    category: { type: String, default: null },
    // [{ key, label }] of the categories that have stories.
    categories: { type: Array, required: true },
    articles: { type: Array, required: true },
    // { [articleId]: debateId } — an empty PHP collection is serialised as [].
    activeDebates: { type: [Object, Array], required: true },
    // { [articleId]: debateId } of the latest finished debate, to open its fluency report.
    finishedDebates: { type: [Object, Array], required: true },
});

const today = new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' });

const query = ref(props.search);
let searchTimer = null;

// Search as the user types, once they pause.
watch(query, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(runSearch, 300);
});

function runSearch() {
    clearTimeout(searchTimer);
    const q = query.value.trim();

    if (q === props.search) {
        return;
    }

    router.get('/feed', q ? { q } : {}, { preserveState: true, preserveScroll: true, replace: true });
}

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
    <Head :title="search ? `Search: ${search}` : `Today's news`" />

    <div class="mx-auto max-w-3xl">
        <header>
            <p class="text-sm font-medium text-ink-400">{{ today }}</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink-100 sm:text-4xl">Pick a story. Take a side.</h1>
            <p class="mt-3 max-w-xl text-ink-400">Read the summary, steal the vocabulary, then defend your opinion out loud.</p>
            <p class="mt-2 max-w-xl text-sm text-ink-500">Select any word or expression to translate it and save it to your words.</p>
        </header>

        <form role="search" class="mt-8" @submit.prevent="runSearch">
            <label for="news-search" class="sr-only">Search the news</label>
            <div class="relative">
                <svg
                    class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-ink-500"
                    viewBox="0 0 20 20"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <circle cx="8.5" cy="8.5" r="5.5" />
                    <path d="m13 13 4 4" stroke-linecap="round" />
                </svg>
                <input
                    id="news-search"
                    v-model="query"
                    type="search"
                    autocomplete="off"
                    placeholder="Search all stories: climate, elections, AI…"
                    class="w-full rounded-xl border border-white/10 bg-ink-900/60 py-3 pr-4 pl-11 text-sm text-ink-100 placeholder-ink-500 transition outline-none focus:border-speaking/60 focus:ring-2 focus:ring-speaking/20"
                />
            </div>
        </form>

        <CategoryChips v-if="!search && categories.length > 0" class="mt-5" :categories="categories" :active="category" />

        <p v-if="search" class="mt-4 text-sm text-ink-400" role="status">
            {{ articles.length === 0 ? 'No' : articles.length }} {{ articles.length === 1 ? 'story matches' : 'stories match' }} “{{ search }}”.
            <button type="button" class="font-medium text-ink-200 underline-offset-4 hover:underline" @click="query = ''">Back to today's news</button>
        </p>

        <div v-else-if="articles.length === 0" class="mt-12 rounded-2xl border border-dashed border-white/10 p-10 text-center">
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
                <ArticleCard
                    v-for="article in day.articles"
                    :key="article.id"
                    :article="article"
                    :active-debate-id="activeDebates[article.id] ?? null"
                    :finished-debate-id="finishedDebates[article.id] ?? null"
                />
            </div>
        </section>
    </div>
</template>
