<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { configureEcho, useChannel, useConnectionStatus, useEcho } from '@laravel/echo-vue';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, toRefs, watch, watchEffect } from 'vue';
import ChatMessage from '../components/ChatMessage.vue';
import FluencyReport from '../components/FluencyReport.vue';
import StateIndicator from '../components/StateIndicator.vue';
import VocabularyChips from '../components/VocabularyChips.vue';
import VoiceOrb from '../components/VoiceOrb.vue';
import { useRecorder } from '../composables/useRecorder';
import { useReplyPlayer } from '../composables/useReplyPlayer';
import AppLayout from '../layouts/AppLayout.vue';
import { unlockAudio } from '../lib/audio';
import { createDebateTurns, uploadTurn } from '../lib/debateTurn';
import { sameOriginPath } from '../lib/http';
import { clock, timeAgo } from '../lib/time';
import { safeHttpUrl } from '../lib/url';

// Realtime is only used on this page, so Echo and Pusher stay out of the entry chunk shared by every page.
configureEcho({
    broadcaster: 'reverb',
});

defineOptions({ layout: AppLayout });

const props = defineProps({
    debate: { type: Object, required: true },
    article: { type: Object, required: true },
    messages: { type: Array, required: true },
});

const MAX_TURN_SECONDS = 60;
const REPORT_POLL_MS = 3_000;
const REPORT_TIMEOUT_MS = 120_000;
const TRANSCRIPT_POLL_MS = 5_000; // while the realtime channel is not live
const SKIP_SPACE_TARGETS = 'input, textarea, select, button, a, summary, [role=button], [tabindex], [contenteditable]';
const SPACE_STATES = ['idle', 'listening', 'speaking'];

// The turn machine (lib/debateTurn.js) owns state, notice and transcript; the page only renders them.
const store = reactive({ state: 'idle', notice: null, messages: [...props.messages] });
const { state, notice, messages: transcript } = toRefs(store);
const scroller = ref(null);
const finishing = ref(false);

const recorder = useRecorder({ maxSeconds: MAX_TURN_SECONDS });
const player = useReplyPlayer();
const connection = useConnectionStatus();
const subscribed = ref(false);
const subscriptionFailed = ref(false);
const live = computed(() => connection.value === 'connected' && subscribed.value);
const liveLabel = computed(() => (live.value ? 'Live' : subscriptionFailed.value ? 'Live updates off' : 'Connecting…'));

const isActive = computed(() => props.debate.status === 'active');
const isWideScreen = window.matchMedia('(min-width: 1024px)').matches;
const paragraphs = computed(() => props.article.summary.split(/\n\s*\n/).filter(Boolean));
const sourceUrl = computed(() => safeHttpUrl(props.article.source_url));
const uploadPath = sameOriginPath(props.debate.audio_upload_url);

// Fluency report (after "Finish"): pushed by DebateEvaluated, or picked up by polling.
const liveFeedback = ref(null);
const addedWords = ref([]);
const evaluationFailed = ref(false);
const feedback = computed(() => liveFeedback.value ?? props.debate.ai_feedback);
const canFinish = computed(
    () => isActive.value && state.value === 'idle' && transcript.value.some((message) => message.role === 'user' && !message.pending),
);

const analyser = computed(() => {
    if (state.value === 'listening') return recorder.analyser.value;
    if (state.value === 'speaking') return player.analyser.value;

    return null;
});
// The orb has no look of its own for "starting": it shows the idle wave while the permission prompt is open.
const orbState = computed(() => (state.value === 'starting' ? 'idle' : state.value));
const orbLabel = computed(
    () =>
        ({
            idle: 'Tap to speak',
            starting: 'Requesting microphone…',
            listening: 'Listening… tap to send',
            thinking: 'Thinking…',
            speaking: 'Speaking… tap to interrupt',
        })[state.value],
);

// A background reload of the transcript only. Resolves with the server's copy; rejects if the reload fails.
function reloadMessages() {
    return new Promise((resolve, reject) => {
        let settled = false;

        router.reload({
            only: ['messages'],
            async: true,
            showProgress: false,
            onSuccess: (page) => {
                settled = true;
                resolve(page.props.messages);
            },
            onFinish: () => {
                if (!settled) reject(new Error("The transcript couldn't be refreshed."));
            },
        });
    });
}

const turns = createDebateTurns({
    store,
    recorder,
    player,
    upload: (blob, turn) => uploadTurn(uploadPath, blob, turn),
    reload: reloadMessages,
});

function syncTranscript() {
    // A failed refresh is retried by the next trigger (reconnect, tab focus, the timer while not live).
    turns.refresh().catch(() => {});
}

// --- Realtime (Laravel Echo → Reverb, private channel debates.{id}) ------------------------------------

useEcho(props.debate.channel, 'UserTurnTranscribed', turns.onUserTranscribed);
useEcho(props.debate.channel, 'AIResponseGenerated', turns.onReply);
useEcho(props.debate.channel, 'DebateTurnFailed', turns.onTurnFailed);

useEcho(props.debate.channel, 'DebateEvaluated', (event) => {
    liveFeedback.value = event.ai_feedback;
    addedWords.value = event.added_words;
    evaluationFailed.value = false;
});

useEcho(props.debate.channel, 'DebateEvaluationFailed', () => {
    evaluationFailed.value = true;
});

// "Live" means subscribed to the private channel, not just connected: a refused subscription shows here.
const realtime = useChannel(props.debate.channel).channel();

realtime.subscribed(() => {
    subscribed.value = true;
    subscriptionFailed.value = false;
    syncTranscript(); // replies sent while the channel was down are picked up now
});

realtime.error(() => {
    subscribed.value = false;
    subscriptionFailed.value = true;
});

watch(connection, (status) => {
    if (status !== 'connected') subscribed.value = false;
});

// Without a live channel, the transcript is checked on a timer.
watchEffect((onCleanup) => {
    if (!isActive.value || live.value) {
        return;
    }

    const poll = setInterval(syncTranscript, TRANSCRIPT_POLL_MS);

    onCleanup(() => clearInterval(poll));
});

// The report may be ready before this page re-subscribes after "Finish": poll as a fallback.
watchEffect((onCleanup) => {
    if (isActive.value || feedback.value || evaluationFailed.value) {
        return;
    }

    const poll = setInterval(() => router.reload({ only: ['debate'], async: true, showProgress: false }), REPORT_POLL_MS);
    const giveUp = setTimeout(() => (evaluationFailed.value = true), REPORT_TIMEOUT_MS);

    onCleanup(() => {
        clearInterval(poll);
        clearTimeout(giveUp);
    });
});

// --- Finishing ----------------------------------------------------------------------------------------

function finishDebate() {
    if (!finishing.value && confirm('Finish the debate and get your fluency report?')) {
        submitFinish();
    }
}

function retryEvaluation() {
    evaluationFailed.value = false;
    submitFinish();
}

// One request at a time: a second click while the first is on its way is ignored.
function submitFinish() {
    if (finishing.value) return;

    finishing.value = true;
    router.post(
        `/debates/${props.debate.id}/finish`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                finishing.value = false;
            },
        },
    );
}

// --- Voice turn ---------------------------------------------------------------------------------------

// Every tap starts (or stops) the audio context first, synchronously, while the browser still counts the gesture.
function press() {
    unlockAudio();
    turns.press();
}

function cancelTurn() {
    turns.cancel();
}

function toggleReplay(message) {
    unlockAudio();

    if (player.playingId.value === message.id) {
        player.stop();
    } else if (state.value === 'idle' || state.value === 'speaking') {
        turns.replay(message);
    }
}

// --- Transcript and keyboard --------------------------------------------------------------------------

async function scrollToBottom() {
    await nextTick();

    const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
    scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior });
}

watch(transcript, scrollToBottom);

// Space is push-to-talk while the page can take a turn: not while typing, on another control, or with a modifier.
function onKeydown(event) {
    if (event.key === 'Escape' && (state.value === 'listening' || state.value === 'starting')) {
        event.preventDefault();
        cancelTurn();

        return;
    }

    if (event.code !== 'Space' || event.repeat || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) {
        return;
    }

    if (event.target instanceof Element && event.target.closest(SKIP_SPACE_TARGETS)) {
        return;
    }

    if (!isActive.value || !SPACE_STATES.includes(state.value)) {
        return;
    }

    event.preventDefault();
    press();
}

// Back in the tab: anything the tutor said while it was hidden may be waiting on the server.
function onVisibilityChange() {
    if (document.visibilityState === 'visible' && isActive.value) {
        syncTranscript();
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    document.addEventListener('visibilitychange', onVisibilityChange);
    scrollToBottom();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    document.removeEventListener('visibilitychange', onVisibilityChange);
    turns.dispose();
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
                <a v-if="sourceUrl" :href="sourceUrl" target="_blank" rel="noopener" class="font-medium text-ink-300 hover:text-ink-100">{{ article.source }}</a>
                <span v-else class="font-medium text-ink-300">{{ article.source }}</span>
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
                        :disabled="finishing"
                        class="rounded-lg border border-white/10 px-2.5 py-1 text-xs font-medium text-ink-300 transition hover:bg-white/5 hover:text-ink-100 disabled:cursor-wait disabled:opacity-50"
                        @click="finishDebate"
                    >
                        Finish
                    </button>
                    <span class="flex items-center gap-2 text-xs text-ink-400" :title="`Realtime connection: ${connection}`">
                        <span
                            class="size-1.5 rounded-full"
                            :class="live ? 'bg-emerald-400 shadow-[0_0_8px_var(--color-emerald-400)]' : 'animate-pulse bg-amber-400 motion-reduce:animate-none'"
                        />
                        <span class="sr-only sm:not-sr-only">{{ liveLabel }}</span>
                    </span>
                </div>
            </header>

            <div ref="scroller" class="max-h-[55dvh] flex-1 space-y-3 overflow-y-auto px-5 py-6 lg:max-h-[420px]" aria-live="polite">
                <div v-if="transcript.length === 0" class="grid h-full place-items-center py-10 text-center">
                    <div class="max-w-xs">
                        <p class="font-medium text-ink-200">Open the debate</p>
                        <p class="mt-1.5 text-sm text-ink-400">What's your take on this story? Tap the mic and argue your position.</p>
                    </div>
                </div>

                <ChatMessage
                    v-for="message in transcript"
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
                <VoiceOrb
                    :state="orbState"
                    :analyser="analyser"
                    :label="orbLabel"
                    :disabled="state === 'thinking' || state === 'starting'"
                    @press="press"
                />

                <p class="-mt-2 text-sm font-medium text-ink-200">
                    {{ orbLabel }}
                    <span v-if="state === 'listening'" class="ml-1.5 font-mono text-listening tabular-nums">{{ clock(recorder.elapsed.value) }}</span>
                </p>

                <div class="mt-2 flex h-6 items-center text-xs text-ink-500">
                    <button
                        v-if="state === 'listening' || state === 'starting'"
                        type="button"
                        class="inline-flex min-h-6 items-center rounded px-2 font-medium text-ink-400 transition hover:text-ink-100"
                        @click="cancelTurn"
                    >
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
