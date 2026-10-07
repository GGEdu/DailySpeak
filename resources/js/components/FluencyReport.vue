<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    feedback: { type: Object, default: null },
    failed: { type: Boolean, default: false },
    addedWords: { type: Array, default: () => [] },
});

defineEmits(['retry']);

const isEmpty = computed(
    () =>
        props.feedback &&
        !props.feedback.crutch_words.length &&
        !props.feedback.grammar_errors.length &&
        !props.feedback.recommended_vocabulary.length,
);
</script>

<template>
    <div class="w-full text-left">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-semibold tracking-tight text-ink-100">Your fluency report</h2>
            <span v-if="feedback" class="rounded-full bg-emerald-400/10 px-2.5 py-0.5 text-xs font-medium text-emerald-300">Debate finished</span>
        </div>

        <!-- Waiting for EvaluateDebate -->
        <div v-if="!feedback && !failed" class="mt-5 flex items-center gap-3 rounded-2xl border border-thinking/20 bg-thinking/[0.06] p-5" role="status">
            <span class="flex gap-1.5" aria-hidden="true">
                <span v-for="dot in 3" :key="dot" class="size-2 animate-bounce rounded-full bg-thinking" :style="{ animationDelay: `${dot * 120}ms` }" />
            </span>
            <p class="text-sm text-ink-200">Analysing your side of the debate…</p>
        </div>

        <div v-else-if="failed" class="mt-5 rounded-2xl border border-listening/25 bg-listening/10 p-5" role="status">
            <p class="text-sm text-listening">We couldn't analyse this debate right now.</p>
            <button type="button" class="mt-3 rounded-lg bg-ink-100 px-3.5 py-2 text-sm font-semibold text-ink-950 transition hover:bg-white" @click="$emit('retry')">
                Try again
            </button>
        </div>

        <p v-else-if="isEmpty" class="mt-5 rounded-2xl border border-white/8 bg-ink-900/60 p-5 text-sm text-ink-300">
            There wasn't enough of your speech to analyse. Next time, argue your point for a few turns before finishing.
        </p>

        <div v-else class="mt-5 space-y-4">
            <section v-if="feedback.recommended_vocabulary.length" class="rounded-2xl border border-white/8 bg-ink-900/60 p-5">
                <h3 class="text-xs font-semibold tracking-wide text-ink-400 uppercase">Words to use next time</h3>
                <ul class="mt-3 space-y-3">
                    <li v-for="item in feedback.recommended_vocabulary" :key="item.word">
                        <p class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-speaking">{{ item.word }}</span>
                            <span v-if="addedWords.includes(item.word.toLowerCase())" class="rounded-full bg-speaking/10 px-2 py-0.5 text-[11px] font-medium text-speaking">
                                Added to your words
                            </span>
                        </p>
                        <p class="mt-1 text-sm text-ink-300 italic">“{{ item.context }}”</p>
                    </li>
                </ul>
                <Link href="/vocabulary" class="mt-4 inline-block text-sm font-medium text-ink-300 underline-offset-4 hover:text-ink-100 hover:underline">
                    Review your words →
                </Link>
            </section>

            <section v-if="feedback.grammar_errors.length" class="rounded-2xl border border-white/8 bg-ink-900/60 p-5">
                <h3 class="text-xs font-semibold tracking-wide text-ink-400 uppercase">Say it like a native</h3>
                <ul class="mt-3 space-y-3 text-sm">
                    <li v-for="(item, index) in feedback.grammar_errors" :key="index">
                        <p class="text-ink-400 line-through decoration-listening/60">{{ item.error }}</p>
                        <p class="mt-0.5 text-ink-100">{{ item.correction }}</p>
                    </li>
                </ul>
            </section>

            <section v-if="feedback.crutch_words.length" class="rounded-2xl border border-white/8 bg-ink-900/60 p-5">
                <h3 class="text-xs font-semibold tracking-wide text-ink-400 uppercase">Words you leaned on</h3>
                <ul class="mt-3 flex flex-wrap gap-1.5">
                    <li v-for="word in feedback.crutch_words" :key="word" class="rounded-md border border-listening/25 bg-listening/10 px-2 py-0.5 text-xs font-medium text-listening">
                        {{ word }}
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
