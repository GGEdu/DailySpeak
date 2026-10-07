<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { useConnectionStatus, useEcho } from '@laravel/echo-vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watchEffect } from 'vue';
import ChatMessage from '../components/ChatMessage.vue';
import FluencyReport from '../components/FluencyReport.vue';
import StateIndicator from '../components/StateIndicator.vue';
import VocabularyChips from '../components/VocabularyChips.vue';
import VoiceOrb from '../components/VoiceOrb.vue';
import { useRecorder } from '../composables/useRecorder';
import { useReplyPlayer } from '../composables/useReplyPlayer';
import AppLayout from '../layouts/AppLayout.vue';
import { extensionFor } from '../lib/audio';
import { postForm } from '../lib/http';
import { clock, timeAgo } from '../lib/time';

defineOptions({ layout: AppLayout });

const props = defineProps({
    debate: { type: Object, required: true },
    article: { type: Object, required: true },
    messages: { type: Array, required: true },
});

const MIN_TURN_SECONDS = 0.6;
const MAX_TURN_SECONDS = 60;
const REPLY_TIMEOUT_MS = 90_000;
const REPORT_POLL_MS = 3_000;
const REPORT_TIMEOUT_MS = 120_000;

// idle → listening (recording) → thinking (STT + LLM + TTS on the server) → speaking (reply playback) → idle
const state = ref('idle');
const conversation = ref([...props.messages]);
const notice = ref(null);
const scroller = ref(null);

const recorder = useRecorder({ maxSeconds: MAX_TURN_SECONDS });
const player = useReplyPlayer();
const connection = useConnectionStatus();
let replyTimeout = null;

const isActive = computed(() => props.debate.status === 'active');

// Fluency report (after "Finish"): pushed by DebateEvaluated, or picked up by polling.
const liveFeedback = ref(null);
const addedWords = ref([]);
const evaluationFailed = ref(false);
const feedback = computed(() => liveFeedback.value ?? props.debate.ai_feedback);
const canFinish = computed(
    () => isActive.value && state.value === 'idle' && conversation.value.some((message) => message.role === 'user' && !message.pending),
);
const isWideScreen = window.matchMedia('(min-width: 1024px)').matches;
const paragraphs = computed(() => props.article.summary.split(/\n\s*\n/).filter(Boolean));
const analyser = computed(() => {
    if (state.value === 'listening') return recorder.analyser.value;
    if (state.value === 'speaking') return player.analyser.value;

    return null;
});
const orbLabel = computed(
    () =>
        ({
            idle: 'Tap to speak',
            listening: 'Listening… tap to send',
            thinking: 'Thinking…',
            speaking: 'Speaking… tap to interrupt',
        })[state.value],
);

// --- Realtime replies (Laravel Echo → Reverb, private channel debates.{id}) ---------------

// The transcript arrives before the reply: replace the "…" bubble while the tutor thinks.
useEcho(props.debate.channel, 'UserTurnTranscribed', (event) => {
    removePendingTurn();
    upsertMessage({ id: event.message_id, role: 'user', transcript: event.transcript });
});

useEcho(props.debate.channel, 'AIResponseGenerated', (event) => {
    clearTimeout(replyTimeout);
    removePendingTurn();
    upsertMessage({ id: event.user_message.id, role: 'user', transcript: event.user_message.transcript });

    const reply = { id: event.message_id, role: 'assistant', transcript: event.transcript, audio_url: event.audio_url };
    upsertMessage(reply);

    // Never talk over the user if they already started their next turn.
    if (state.value !== 'listening') {
        speak(reply);
    }
});

useEcho(props.debate.channel, 'DebateTurnFailed', (event) => {
    failTurn(
        event.reason === 'no_speech'
            ? "We couldn't hear anything in that recording. Try again a little closer to the mic."
            : 'Something went wrong while answering that turn. Please try again.',
    );
});

useEcho(props.debate.channel, 'DebateEvaluated', (event) => {
    liveFeedback.value = event.ai_feedback;
    addedWords.value = event.added_words;
    evaluationFailed.value = false;
});

useEcho(props.debate.channel, 'DebateEvaluationFailed', () => {
    evaluationFailed.value = true;
});

// The report may be ready before this page re-subscribes after "Finish": poll as a fallback.
watchEffect((onCleanup) => {
    if (isActive.value || feedback.value || evaluationFailed.value) {
        return;
    }

    const poll = setInterval(() => router.reload({ only: ['debate'] }), REPORT_POLL_MS);
    const giveUp = setTimeout(() => (evaluationFailed.value = true), REPORT_TIMEOUT_MS);

    onCleanup(() => {
        clearInterval(poll);
        clearTimeout(giveUp);
    });
});

function finishDebate() {
    if (confirm('Finish the debate and get your fluency report?')) {
        router.post(`/debates/${props.debate.id}/finish`, {}, { preserveScroll: true });
    }
}

function retryEvaluation() {
    evaluationFailed.value = false;
    router.post(`/debates/${props.debate.id}/finish`, {}, { preserveScroll: true });
}

// --- Voice turn -----------------------------------------------------------------------------

async function press() {
    notice.value = null;

    if (state.value === 'idle') {
        await startListening();
    } else if (state.value === 'listening') {
        await sendTurn();
    } else if (state.value === 'speaking') {
        player.stop(); // barge in
        await startListening();
    }
}

async function startListening() {
    try {
        await recorder.start({ onMaxDuration: sendTurn });
        state.value = 'listening';
    } catch (error) {
        state.value = 'idle';
        notice.value = {
            tone: 'error',
            text: error?.name === 'NotAllowedError' ? 'Microphone access is blocked. Allow it in your browser to start talking.' : error.message,
        };
    }
}

async function sendTurn() {
    if (state.value !== 'listening') {
        return;
    }

    // Leave "listening" before awaiting, so the max-duration timer cannot send the turn twice.
    state.value = 'thinking';
    const recording = await recorder.stop();

    if (!recording || recording.duration < MIN_TURN_SECONDS) {
        state.value = 'idle';
        notice.value = { tone: 'info', text: 'That was very short. Tap, speak your mind, then tap again to send.' };

        return;
    }

    conversation.value.push({ id: 'pending', role: 'user', pending: true });
    scrollToBottom();

    try {
        const body = new FormData();
        body.append('audio', recording.blob, `turn.${extensionFor(recording.blob.type)}`);
        await postForm(props.debate.audio_upload_url, body);

        replyTimeout = setTimeout(() => failTurn('The tutor is taking too long to answer. Please try again.'), REPLY_TIMEOUT_MS);
    } catch (error) {
        failTurn(error.message);
    }
}

function cancelTurn() {
    recorder.cancel();
    state.value = 'idle';
}

function failTurn(text) {
    clearTimeout(replyTimeout);
    removePendingTurn();

    if (state.value === 'thinking') {
        state.value = 'idle';
    }

    notice.value = { tone: 'error', text };
}

// --- Playback -----------------------------------------------------------------------------

async function speak(message) {
    state.value = 'speaking';

    try {
        await player.play(message.audio_url, message.id);
    } catch (error) {
        notice.value =
            error?.name === 'NotAllowedError'
                ? { tone: 'info', text: 'Your browser blocked autoplay. Press “Replay” to hear the answer.' }
                : { tone: 'error', text: "The tutor's audio couldn't be played, but you can read the reply above." };
    } finally {
        if (state.value === 'speaking') {
            state.value = 'idle';
        }
    }
}

function toggleReplay(message) {
    if (player.playingId.value === message.id) {
        player.stop();
    } else if (state.value === 'idle' || state.value === 'speaking') {
        speak(message);
    }
}

// --- Transcript helpers ---------------------------------------------------------------------

function upsertMessage(message) {
    const index = conversation.value.findIndex((existing) => existing.id === message.id);

    if (index === -1) {
        conversation.value.push(message);
    } else {
        conversation.value[index] = { ...conversation.value[index], ...message };
    }

    scrollToBottom();
}

function removePendingTurn() {
    conversation.value = conversation.value.filter((message) => !message.pending);
}

async function scrollToBottom() {
    await nextTick();
    scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior: 'smooth' });
}

// Space bar as push-to-talk toggle (unless typing or focusing another control).
function onKeydown(event) {
    if (event.code !== 'Space' || event.repeat || event.target.closest('input, textarea, select, button, a, [contenteditable]')) {
        return;
    }

    event.preventDefault();

    if (isActive.value && state.value !== 'thinking') {
        press();
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    scrollToBottom();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    clearTimeout(replyTimeout);
});
</script>

<template>
    <Head :title="article.title" />

    <div class="grid gap-8 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-10">
        <!-- The story being debated -->
        <aside class="lg:sticky lg:top-28 lg:self-start">
            <Link href="/feed" class="inline-flex items-center gap-1.5 text-sm text-ink-400 transition hover:text-ink-100">
                <span aria-hidden="true">←</span> Today's news
            </Link>

            <p class="mt-6 flex items-center gap-2 text-xs text-ink-400">
                <a :href="article.source_url" target="_blank" rel="noopener" class="font-medium text-ink-300 hover:text-ink-100">{{ article.source }}</a>
                <span aria-hidden="true">·</span>
                <time :datetime="article.published_at">{{ timeAgo(article.published_at) }}</time>
            </p>
            <h1 data-selectable class="mt-2 text-2xl font-semibold tracking-tight text-balance text-ink-100">{{ article.title }}</h1>

            <!-- Collapsed on phones so the microphone stays within reach. -->
            <details class="group mt-4" :open="isWideScreen">
                <summary class="cursor-pointer list-none text-sm font-medium text-ink-400 transition hover:text-ink-200 lg:hidden">
                    <span class="group-open:hidden">Show summary</span>
                    <span class="hidden group-open:inline">Hide summary</span>
                </summary>
                <div class="mt-3 space-y-3 text-[15px] leading-relaxed text-ink-300 lg:mt-0">
                    <p v-for="(paragraph, index) in paragraphs" :key="index" data-selectable>{{ paragraph }}</p>
                </div>
            </details>

            <div class="mt-6 rounded-2xl border border-white/8 bg-ink-900/60 p-5">
                <p class="text-xs font-semibold tracking-wide text-ink-400 uppercase">Try to use these</p>
                <VocabularyChips :words="article.key_vocabulary" class="mt-3" />
            </div>
        </aside>

        <!-- Voice debate -->
        <section class="flex min-h-[calc(100vh-11rem)] flex-col overflow-hidden rounded-3xl border border-white/8 bg-ink-900/50 lg:min-h-[640px]">
            <header class="flex items-center justify-between gap-3 border-b border-white/5 px-4 py-3.5 sm:px-5">
                <StateIndicator v-if="isActive" :state="state" />
                <span v-else class="text-xs font-medium text-ink-400">Debate finished</span>
                <div class="flex items-center gap-3">
                    <button
                        v-if="canFinish"
                        type="button"
                        class="rounded-lg border border-white/10 px-2.5 py-1 text-xs font-medium text-ink-300 transition hover:bg-white/5 hover:text-ink-100"
                        @click="finishDebate"
                    >
                        Finish
                    </button>
                    <span class="flex items-center gap-2 text-xs text-ink-400" :title="`Realtime connection: ${connection}`">
                        <span class="size-1.5 rounded-full" :class="connection === 'connected' ? 'bg-emerald-400 shadow-[0_0_8px_var(--color-emerald-400)]' : 'animate-pulse bg-amber-400'" />
                        <span class="sr-only sm:not-sr-only">{{ connection === 'connected' ? 'Live' : 'Connecting…' }}</span>
                    </span>
                </div>
            </header>

            <div ref="scroller" class="flex-1 space-y-3 overflow-y-auto px-5 py-6 lg:max-h-[420px]" aria-live="polite">
                <div v-if="conversation.length === 0" class="grid h-full place-items-center py-10 text-center">
                    <div class="max-w-xs">
                        <p class="font-medium text-ink-200">Open the debate</p>
                        <p class="mt-1.5 text-sm text-ink-400">What's your take on this story? Tap the mic and argue your position.</p>
                    </div>
                </div>

                <ChatMessage
                    v-for="message in conversation"
                    :key="message.id"
                    :message="message"
                    :playing="player.playingId.value === message.id"
                    @play="toggleReplay(message)"
                />
            </div>

            <footer v-if="!isActive" class="border-t border-white/5 px-5 py-6">
                <FluencyReport :feedback="feedback" :failed="evaluationFailed" :added-words="addedWords" @retry="retryEvaluation" />
            </footer>

            <footer v-else class="flex flex-col items-center border-t border-white/5 px-5 pt-4 pb-7">
                <VoiceOrb :state="state" :analyser="analyser" :label="orbLabel" :disabled="state === 'thinking'" @press="press" />

                <p class="-mt-2 text-sm font-medium text-ink-200">
                    {{ orbLabel }}
                    <span v-if="state === 'listening'" class="ml-1.5 font-mono text-listening tabular-nums">{{ clock(recorder.elapsed.value) }}</span>
                </p>

                <div class="mt-2 h-5 text-xs text-ink-500">
                    <button v-if="state === 'listening'" type="button" class="font-medium text-ink-400 transition hover:text-ink-100" @click="cancelTurn">
                        Cancel
                    </button>
                    <span v-else-if="state === 'idle' && isActive" class="hidden sm:inline">
                        or press <kbd class="rounded border border-white/10 bg-white/5 px-1.5 py-0.5 font-sans text-ink-300">Space</kbd>
                    </span>
                </div>

                <p
                    v-if="notice"
                    role="status"
                    class="mt-4 max-w-md rounded-xl px-4 py-2.5 text-center text-sm"
                    :class="notice.tone === 'error' ? 'border border-listening/25 bg-listening/10 text-listening' : 'border border-white/10 bg-white/5 text-ink-300'"
                >
                    {{ notice.text }}
                </p>
            </footer>
        </section>
    </div>
</template>
