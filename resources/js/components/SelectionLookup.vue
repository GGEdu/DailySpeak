<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { activeLookup, closeLookup, explain, friendlyError, openLookup, saveWord, selectedVocabulary, speak } from '../lib/lookup';

const page = usePage();
// Explanations depend on who asks and at which level.
const scope = computed(() => `${page.props.auth.user.id}:${page.props.auth.user.current_level}`);

const panel = ref(null);
const status = ref('idle'); // loading | ready | error
const explanation = ref(null);
const error = ref('');
const saving = ref('idle'); // saving | saved | error
const position = ref({ top: 0, left: 0, width: 340 });
const canSpeak = typeof window !== 'undefined' && 'speechSynthesis' in window;

let request = null;
let timer = null;
let frame = null;
let mouseDown = false;

// Mouse selections are final on release; on touch screens the handles keep moving after
// the finger lifts, so wait for the selection to settle.
function check(delay) {
    clearTimeout(timer);
    timer = setTimeout(() => {
        const found = selectedVocabulary();
        if (found && (found.text !== activeLookup.value?.text || found.context !== activeLookup.value?.context)) {
            openLookup(found.text, found.context, found.target);
        }
    }, delay);
}

function onPointerDown(event) {
    mouseDown = event.pointerType === 'mouse';
    if (activeLookup.value && !panel.value?.contains(event.target)) closeLookup();
}

// Only a release inside selectable text can have made a selection: releasing on a button
// (e.g. a vocabulary chip) must not reopen whatever text is still selected elsewhere.
function onPointerUp(event) {
    mouseDown = false;
    if (event.target instanceof Element && event.target.closest('[data-selectable]')) check(event.pointerType === 'mouse' ? 0 : 600);
}

// Touch and keyboard selections; a mouse drag still in progress waits for the release.
function onSelectionChange() {
    if (!mouseDown && !document.getSelection()?.isCollapsed) check(600);
}

function onKeydown(event) {
    if (event.key === 'Escape' && activeLookup.value) closeLookup();
}

// Below the selection by default: on phones the browser's own copy/share menu sits above it.
// Recomputed from the live selection, so it follows the text when the page or the chat scrolls.
function place() {
    const target = activeLookup.value?.target;
    if (!target) return;

    const rect = target.getBoundingClientRect();
    if (rect.bottom < 0 || rect.top > window.innerHeight || (rect.width === 0 && rect.height === 0)) {
        closeLookup();
        return;
    }

    const width = Math.min(340, window.innerWidth - 16);
    const height = panel.value?.offsetHeight ?? 180;
    const fitsBelow = window.innerHeight - rect.bottom >= height + 12 || rect.top < height + 12;

    position.value = {
        top: fitsBelow ? rect.bottom + 10 : rect.top - height - 10,
        left: Math.min(Math.max(8, rect.left + rect.width / 2 - width / 2), window.innerWidth - width - 8),
        width,
    };
}

function follow() {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(place);
}

async function lookUp(lookup) {
    request?.abort();
    request = new AbortController();
    status.value = 'loading';
    explanation.value = null;
    error.value = '';

    try {
        explanation.value = await explain(lookup.text, lookup.context, scope.value, request.signal);
        status.value = 'ready';
    } catch (e) {
        if (e.name === 'AbortError') return;
        error.value = friendlyError(e);
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
        if (activeLookup.value !== lookup) return;
        saving.value = 'saved';
        // The "Words" badge in the header, and the lists when "Your words" is open.
        router.reload({ only: ['dueWords', 'due', 'words'] });
    } catch (e) {
        if (activeLookup.value !== lookup) return;
        error.value = friendlyError(e);
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
    // Opened from the keyboard (a vocabulary chip): take focus so Tab reaches the panel's buttons.
    if (lookup.focus) panel.value?.focus();
    lookUp(lookup);
});

// A new page: whatever was selected on the previous one is gone.
const stopNavigationListener = router.on('navigate', () => closeLookup());

onMounted(() => {
    document.addEventListener('pointerdown', onPointerDown);
    document.addEventListener('pointerup', onPointerUp);
    document.addEventListener('selectionchange', onSelectionChange);
    document.addEventListener('keydown', onKeydown);
    // Capture: also scrolls inside containers such as the debate's chat.
    document.addEventListener('scroll', follow, { capture: true, passive: true });
    window.addEventListener('resize', follow);
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    cancelAnimationFrame(frame);
    request?.abort();
    stopNavigationListener();
    document.removeEventListener('pointerdown', onPointerDown);
    document.removeEventListener('pointerup', onPointerUp);
    document.removeEventListener('selectionchange', onSelectionChange);
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('scroll', follow, { capture: true });
    window.removeEventListener('resize', follow);
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="activeLookup"
            ref="panel"
            role="dialog"
            tabindex="-1"
            :aria-label="`Meaning of “${activeLookup.text}”`"
            class="fixed z-50 rounded-2xl border border-white/10 bg-ink-850 p-4 text-left shadow-2xl ring-1 shadow-black/60 ring-black/40 outline-none"
            :style="{ top: `${position.top}px`, left: `${position.left}px`, width: `${position.width}px` }"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="flex min-w-0 items-center gap-1.5">
                    <p class="truncate font-semibold text-ink-100">{{ activeLookup.text }}</p>
                    <button
                        v-if="canSpeak"
                        type="button"
                        class="shrink-0 rounded-md p-1.5 text-ink-400 transition hover:bg-white/5 hover:text-ink-100"
                        :aria-label="`Listen to “${activeLookup.text}”`"
                        @click="speak(activeLookup.text)"
                    >
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M11 5 6 9H3v6h3l5 4V5Z" />
                            <path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13" />
                        </svg>
                    </button>
                </div>
                <button type="button" class="-mt-1.5 -mr-1.5 shrink-0 rounded-md p-2 text-ink-400 transition hover:bg-white/5 hover:text-ink-100" aria-label="Close" @click="closeLookup">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M6 6l12 12M18 6 6 18" />
                    </svg>
                </button>
            </div>

            <div aria-live="polite">
                <p v-if="status === 'loading'" class="mt-3 flex items-center gap-2 text-sm text-ink-300">
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
                    <p v-if="explanation.part_of_speech" class="mt-0.5 text-xs font-semibold tracking-wide text-ink-400 uppercase">{{ explanation.part_of_speech }}</p>
                    <p v-if="explanation.definition" class="mt-2 text-sm leading-relaxed text-ink-200">{{ explanation.definition }}</p>
                    <p v-if="explanation.example" class="mt-2 text-sm leading-relaxed text-ink-300 italic">“{{ explanation.example }}”</p>
                    <ul v-if="explanation.synonyms?.length" class="mt-3 flex flex-wrap gap-1.5" aria-label="Synonyms">
                        <li v-for="(synonym, index) in explanation.synonyms" :key="index" class="rounded-md border border-white/10 px-2 py-0.5 text-xs text-ink-300">{{ synonym }}</li>
                    </ul>
                </div>
            </div>

            <div class="mt-4 flex items-center justify-between gap-3 border-t border-white/5 pt-3">
                <p v-if="saving === 'saved'" class="text-sm text-emerald-300" role="status">
                    Saved ·
                    <Link href="/vocabulary" class="font-medium underline-offset-4 hover:underline">Your words</Link>
                </p>
                <p v-else-if="saving === 'error'" class="text-sm text-listening" role="status">{{ error || "Couldn't save it. Try again." }}</p>
                <p v-else class="text-xs text-ink-400">Save it to practise it later.</p>

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
