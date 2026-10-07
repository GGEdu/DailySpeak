<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '../layouts/AppLayout.vue';
import { timeAgo } from '../lib/time';

defineOptions({ layout: AppLayout });

const props = defineProps({
    due: { type: Array, required: true },
    words: { type: Array, required: true },
    maxLevel: { type: Number, required: true },
});

const current = computed(() => props.due[0] ?? null);
const revealed = ref(false);
const submitting = ref(false);
const mastered = computed(() => props.words.filter((word) => word.mastery_level === props.maxLevel).length);
// Soonest upcoming review (`words` is sorted alphabetically, not by date).
const nextReview = computed(
    () =>
        props.words
            .map((word) => word.next_review_at)
            .filter((date) => new Date(date) > new Date())
            .sort((a, b) => new Date(a) - new Date(b))[0] ?? null,
);

// A new card starts hidden.
watch(current, () => (revealed.value = false));

function answer(remembered) {
    submitting.value = true;
    router.post(`/vocabulary/${current.value.id}/review`, { remembered }, { preserveScroll: true, onFinish: () => (submitting.value = false) });
}

function remove(word) {
    if (confirm(`Remove “${word.word}” from your words?`)) {
        router.delete(`/vocabulary/${word.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Your words" />

    <div class="mx-auto max-w-3xl">
        <header>
            <h1 class="text-3xl font-semibold tracking-tight text-ink-100">Your words</h1>
            <p class="mt-3 text-ink-400">
                Vocabulary recommended in your fluency reports. Words you remember come back less and less often; the ones you forget come back sooner.
            </p>
            <p v-if="words.length" class="mt-4 text-sm text-ink-300">
                {{ due.length }} due now · {{ words.length - mastered }} learning · {{ mastered }} mastered
            </p>
        </header>

        <!-- Review card -->
        <section class="mt-10">
            <div v-if="current" class="rounded-3xl border border-white/8 bg-ink-900/60 p-8 text-center">
                <p class="text-xs font-semibold tracking-wide text-ink-500 uppercase">Do you remember how to use it?</p>
                <p class="mt-4 text-4xl font-semibold tracking-tight text-ink-100">{{ current.word }}</p>
                <p class="mt-2 text-xs text-ink-500">Level {{ current.mastery_level }} of {{ maxLevel }}</p>

                <p v-if="revealed" class="mx-auto mt-6 max-w-lg text-[15px] text-ink-300 italic">
                    {{ current.context ? `“${current.context}”` : 'No example sentence saved for this word.' }}
                </p>

                <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                    <button
                        v-if="!revealed"
                        type="button"
                        class="rounded-xl border border-white/10 px-5 py-2.5 text-sm font-medium text-ink-200 transition hover:bg-white/5"
                        @click="revealed = true"
                    >
                        Show example
                    </button>
                    <template v-else>
                        <button
                            type="button"
                            :disabled="submitting"
                            class="rounded-xl border border-listening/30 px-5 py-2.5 text-sm font-semibold text-listening transition hover:bg-listening/10 disabled:opacity-50"
                            @click="answer(false)"
                        >
                            Still learning
                        </button>
                        <button
                            type="button"
                            :disabled="submitting"
                            class="rounded-xl bg-ink-100 px-5 py-2.5 text-sm font-semibold text-ink-950 transition hover:bg-white disabled:opacity-50"
                            @click="answer(true)"
                        >
                            I knew it
                        </button>
                    </template>
                </div>
                <p class="mt-6 text-xs text-ink-500">{{ due.length }} {{ due.length === 1 ? 'word' : 'words' }} left in this session</p>
            </div>

            <div v-else-if="words.length" class="rounded-3xl border border-emerald-400/20 bg-emerald-400/[0.06] p-8 text-center">
                <p class="font-semibold text-emerald-200">All caught up</p>
                <p class="mt-2 text-sm text-ink-300">Next review {{ nextReview ? timeAgo(nextReview) : 'soon' }}.</p>
            </div>

            <div v-else class="rounded-3xl border border-dashed border-white/10 p-10 text-center">
                <p class="font-medium text-ink-200">No words yet</p>
                <p class="mt-2 text-sm text-ink-400">Finish a debate to get your fluency report: its recommended words land here.</p>
                <Link href="/feed" class="mt-5 inline-block rounded-xl bg-ink-100 px-4 py-2.5 text-sm font-semibold text-ink-950 transition hover:bg-white">
                    Pick a story
                </Link>
            </div>
        </section>

        <!-- Full list -->
        <section v-if="words.length" class="mt-12">
            <h2 class="mb-4 flex items-center gap-3 text-xs font-semibold tracking-wider text-ink-500 uppercase">
                All words
                <span class="h-px flex-1 bg-white/5" />
            </h2>
            <ul class="divide-y divide-white/5 rounded-2xl border border-white/8 bg-ink-900/60">
                <li v-for="word in words" :key="word.id" class="flex items-start justify-between gap-4 px-5 py-4">
                    <div class="min-w-0">
                        <p class="font-medium text-ink-100">{{ word.word }}</p>
                        <p v-if="word.context" class="mt-1 text-sm text-ink-400 italic">“{{ word.context }}”</p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1 text-xs">
                        <span :class="word.mastery_level === maxLevel ? 'text-emerald-300' : 'text-ink-300'">
                            {{ word.mastery_level === maxLevel ? 'Mastered' : `Level ${word.mastery_level}/${maxLevel}` }}
                        </span>
                        <span class="text-ink-500">{{ new Date(word.next_review_at) <= new Date() ? 'due now' : `review ${timeAgo(word.next_review_at)}` }}</span>
                        <button type="button" class="text-ink-500 transition hover:text-listening" @click="remove(word)">Remove</button>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
