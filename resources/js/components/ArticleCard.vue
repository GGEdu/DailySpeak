<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { timeAgo } from '../lib/time';
import VocabularyChips from './VocabularyChips.vue';

const props = defineProps({
    article: { type: Object, required: true },
    activeDebateId: { type: Number, default: null },
});

const expanded = ref(false);
const paragraphs = computed(() => props.article.summary.split(/\n\s*\n/).filter(Boolean));
</script>

<template>
    <article class="group rounded-2xl border border-white/8 bg-ink-900/60 p-6 transition hover:border-white/15 sm:p-7">
        <div class="flex items-center gap-2 text-xs text-ink-400">
            <a :href="article.source_url" target="_blank" rel="noopener" class="font-medium text-ink-300 hover:text-ink-100">{{ article.source }}</a>
            <span aria-hidden="true">·</span>
            <time :datetime="article.published_at">{{ timeAgo(article.published_at) }}</time>
        </div>

        <h2 class="mt-3 text-lg font-semibold tracking-tight text-balance text-ink-100 sm:text-xl">{{ article.title }}</h2>

        <div class="mt-3 space-y-3 text-[15px] leading-relaxed text-ink-300">
            <p v-for="(paragraph, index) in expanded ? paragraphs : paragraphs.slice(0, 1)" :key="index">{{ paragraph }}</p>
        </div>
        <button
            v-if="paragraphs.length > 1"
            type="button"
            class="mt-2 text-sm font-medium text-ink-400 transition hover:text-ink-100"
            :aria-expanded="expanded"
            @click="expanded = !expanded"
        >
            {{ expanded ? 'Show less' : 'Read full summary' }}
        </button>

        <div class="mt-5 flex flex-col gap-5 border-t border-white/5 pt-5 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="mb-2 text-xs font-medium tracking-wide text-ink-500 uppercase">Key vocabulary</p>
                <VocabularyChips :words="article.key_vocabulary" />
            </div>

            <Link
                v-if="activeDebateId"
                :href="`/debates/${activeDebateId}`"
                class="shrink-0 rounded-xl border border-speaking/40 px-4 py-2.5 text-center text-sm font-semibold text-speaking transition hover:bg-speaking/10"
            >
                Continue debate
            </Link>
            <Link
                v-else
                :href="`/news-articles/${article.id}/debate`"
                method="post"
                as="button"
                class="shrink-0 rounded-xl bg-ink-100 px-4 py-2.5 text-sm font-semibold text-ink-950 transition hover:bg-white"
            >
                Debate this
            </Link>
        </div>
    </article>
</template>
