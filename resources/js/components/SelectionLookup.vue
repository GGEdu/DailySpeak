<script setup>
import { Link, router } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { activeLookup, closeLookup, explain, openLookup, saveWord, selectedVocabulary, speak } from '../lib/lookup';

const panel = ref(null);
const status = ref('idle'); // loading | ready | error
const explanation = ref(null);
const error = ref('');
const saving = ref('idle'); // saving | saved | error
const position = ref({ top: 0, left: 0, width: 340 });
const canSpeak = typeof window !== 'undefined' && 'speechSynthesis' in window;

let request = null;
let timer = null;

// Mouse selections are final on release; on touch screens the handles keep moving after
// the finger lifts, so wait for the selection to settle.
function check(delay) {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const found = selectedVocabulary();
        if (found && (found.text !== activeLookup.value?.text || found.context !== activeLookup.value?.context)) {
            openLookup(found.text, found.context, found.rect);
        }
    }, delay);
}

function onPointerUp(event) {
    if (!panel.value?.contains(event.target)) check(event.pointerType === 'mouse' ? 0 : 600);
}

function onSelectionChange() {
    if (!document.getSelection()?.isCollapsed) check(600);
}

function onPointerDown(event) {
    if (activeLookup.value && !panel.value?.contains(event.target)) closeLookup();
}

function onKeydown(event) {
    if (event.key === 'Escape' && activeLookup.value) closeLookup();
}

// Below the selection by default: on phones the browser's own copy/share menu sits above it.
function place() {
    const anchor = activeLookup.value?.anchor;
    if (!anchor) return;

    const width = Math.min(340, window.innerWidth - 16);
    const height = panel.value?.offsetHeight ?? 180;
    const roomBelow = window.innerHeight - (anchor.bottom - window.scrollY);
    const top = roomBelow >= height + 12 || anchor.top - window.scrollY < height + 12 ? anchor.bottom + 10 : anchor.top - height - 10;
    const left = Math.min(Math.max(8, anchor.left + anchor.width / 2 - width / 2 - window.scrollX), window.innerWidth - width - 8) + window.scrollX;

    position.value = { top, left, width };
}

async function lookUp(lookup) {
    request?.abort();
    request = new AbortController();
    status.value = 'loading';
    explanation.value = null;
    error.value = '';

    try {
        explanation.value = await explain(lookup.text, lookup.context, request.signal);
        status.value = 'ready';
    } catch (e) {
        if (e.name === 'AbortError') return;
        error.value = e.message;
        status.value = 'error';
    }

    await nextTick();
    place();
}

async function save() {
    const lookup = activeLookup.value;
    saving.value = 'saving';

    try {
        await saveWord(lookup.text, lookup.context);
        saving.value = 'saved';
        // The "Words" badge in the header, and the lists when "Your words" is open.
        router.reload({ only: ['dueWords', 'due', 'words'] });
    } catch {
        saving.value = 'error';
    }
}

watch(activeLookup, async (lookup) => {
    saving.value = 'idle';

    if (!lookup) {
        request?.abort();
        status.value = 'idle';
        return;
    }

    await nextTick();
    place();
    lookUp(lookup);
});

// A new page: whatever was selected on the previous one is gone.
const stopNavigationListener = router.on('navigate', () => closeLookup());

onMounted(() => {
    document.addEventListener('pointerup', onPointerUp);
    document.addEventListener('pointerdown', onPointerDown);
    document.addEventListener('selectionchange', onSelectionChange);
    document.addEventListener('keydown', onKeydown);
    window.addEventListener('resize', place);
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    request?.abort();
    stopNavigationListener();
    document.removeEventListener('pointerup', onPointerUp);
    document.removeEventListener('pointerdown', onPointerDown);
    document.removeEventListener('selectionchange', onSelectionChange);
    document.removeEventListener('keydown', onKeydown);
    window.removeEventListener('resize', place);
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="activeLookup"
            ref="panel"
            role="dialog"
            :aria-label="`Meaning of “${activeLookup.text}”`"
            class="absolute z-50 rounded-2xl border border-white/10 bg-ink-850/95 p-4 text-left shadow-2xl shadow-black/50 ring-1 ring-black/40 backdrop-blur-xl"
            :style="{ top: `${position.top}px`, left: `${position.left}px`, width: `${position.width}px` }"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="flex min-w-0 items-center gap-2">
                    <p class="truncate font-semibold text-ink-100">{{ activeLookup.text }}</p>
                    <button
                        v-if="canSpeak"
                        type="button"
                        class="shrink-0 rounded-md p-1 text-ink-400 transition hover:bg-white/5 hover:text-ink-100"
                        :aria-label="`Listen to “${activeLookup.text}”`"
                        @click="speak(activeLookup.text)"
                    >
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M11 5 6 9H3v6h3l5 4V5Z" />
                            <path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13" />
                        </svg>
                    </button>
                </div>
                <button type="button" class="-mt-1 -mr-1 shrink-0 rounded-md p-1 text-ink-500 transition hover:bg-white/5 hover:text-ink-100" aria-label="Close" @click="closeLookup">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M6 6l12 12M18 6 6 18" />
                    </svg>
                </button>
            </div>

            <div aria-live="polite">
                <p v-if="status === 'loading'" class="mt-3 flex items-center gap-2 text-sm text-ink-400">
                    <span class="flex gap-1" aria-hidden="true">
                        <span v-for="dot in 3" :key="dot" class="size-1.5 animate-pulse rounded-full bg-thinking" :style="{ animationDelay: `${dot * 150}ms` }" />
                    </span>
                    Translating…
                </p>

                <div v-else-if="status === 'error'" class="mt-3 text-sm">
                    <p class="text-listening">{{ error }}</p>
                    <button type="button" class="mt-2 font-medium text-ink-300 underline-offset-4 hover:text-ink-100 hover:underline" @click="lookUp(activeLookup)">Try again</button>
                </div>

                <div v-else-if="explanation" class="mt-2">
                    <p class="text-lg leading-snug font-semibold text-speaking">{{ explanation.translation }}</p>
                    <p v-if="explanation.part_of_speech" class="mt-0.5 text-[11px] font-semibold tracking-wide text-ink-500 uppercase">{{ explanation.part_of_speech }}</p>
                    <p v-if="explanation.definition" class="mt-2 text-sm leading-relaxed text-ink-200">{{ explanation.definition }}</p>
                    <p v-if="explanation.example" class="mt-2 text-sm leading-relaxed text-ink-400 italic">“{{ explanation.example }}”</p>
                    <ul v-if="explanation.synonyms?.length" class="mt-3 flex flex-wrap gap-1.5" aria-label="Synonyms">
                        <li v-for="synonym in explanation.synonyms" :key="synonym" class="rounded-md border border-white/10 px-2 py-0.5 text-xs text-ink-300">{{ synonym }}</li>
                    </ul>
                </div>
            </div>

            <div class="mt-4 flex items-center justify-between gap-3 border-t border-white/5 pt-3">
                <p v-if="saving === 'saved'" class="text-sm text-emerald-300" role="status">
                    Saved ·
                    <Link href="/vocabulary" class="font-medium underline-offset-4 hover:underline">Your words</Link>
                </p>
                <p v-else-if="saving === 'error'" class="text-sm text-listening" role="status">Couldn't save it. Try again.</p>
                <p v-else class="text-xs text-ink-500">Save it to practise it later.</p>

                <button
                    v-if="saving !== 'saved'"
                    type="button"
                    :disabled="saving === 'saving'"
                    class="shrink-0 rounded-lg bg-ink-100 px-3 py-1.5 text-sm font-semibold text-ink-950 transition hover:bg-white disabled:opacity-50"
                    @click="save"
                >
                    {{ saving === 'saving' ? 'Saving…' : 'Save word' }}
                </button>
            </div>
        </div>
    </Teleport>
</template>
