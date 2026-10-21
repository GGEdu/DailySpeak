import { postForm } from './http.js';
import { extensionFor } from './audio.js';
import { addMissing, findTurnMessages, latestId, mergeMessages, upsertMessage, withoutPending } from './transcript.js';

/**
 * One voice turn as a state machine, apart from the page so it can be tested with fake timers,
 * recorder, player and transport. idle → starting (waiting for the microphone permission) → listening
 * → thinking (uploaded, waiting for the tutor) → speaking → idle.
 *
 * Each turn gets its id here, before the upload, and the server keeps it: the 202 and the realtime events
 * name it, and an upload sent twice with the same id is stored and answered once. The event of the turn
 * being answered drives the state and the audio; events of other turns only add their messages to the
 * transcript. An event without a `turn` (a server that does not name them yet) is taken as the current turn.
 */

export const MIN_TURN_SECONDS = 0.6;
export const REPLY_TIMEOUT_MS = 130_000;
const RELOAD_TIMEOUT_MS = 20_000;
const MAX_UPLOAD_ATTEMPTS = 2;

export const TEXT = {
    short: 'That was very short. Tap, speak your mind, then tap again to send.',
    timeout: 'The tutor is taking too long to answer. Please try again.',
    upload: "We couldn't reach the server. Check your connection and try again.",
    lost: 'The microphone stopped working. Try again.',
    transcript: "The transcript couldn't be refreshed.",
    autoplay: 'Your browser blocked autoplay. Press “Replay” to hear the answer.',
    playback: "The tutor's audio couldn't be played, but you can read the reply above.",
    noSpeech: "We couldn't hear anything in that recording. Try again a little closer to the mic.",
    failed: 'Something went wrong while answering that turn. Please try again.',
    ended: 'This debate has already finished, so that turn was not answered.',
};

/**
 * A random UUID for a turn. crypto.randomUUID needs a secure context, so plain getRandomValues backs it up.
 */
export function randomTurnId(cryptoApi = globalThis.crypto) {
    if (typeof cryptoApi?.randomUUID === 'function') {
        return cryptoApi.randomUUID();
    }

    const bytes = cryptoApi.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40; // version 4
    bytes[8] = (bytes[8] & 0x3f) | 0x80; // RFC 4122 variant
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/**
 * Upload one recording. A request that got no answer (a dropped connection, or the timeout in postForm)
 * is sent once more with the same recording and the same turn id, which the server stores only once, so
 * a first request that did arrive is not paid twice. A 409 means that first request is still being
 * stored: the turn is on its way. Any other answer from the server is final.
 */
export async function uploadTurn(url, blob, turn = null, send = postForm) {
    for (let attempt = 1; attempt <= MAX_UPLOAD_ATTEMPTS; attempt += 1) {
        const body = new FormData();
        body.append('audio', blob, `turn.${extensionFor(blob.type)}`);

        if (turn) {
            body.append('turn', turn);
        }

        try {
            return await send(url, body);
        } catch (error) {
            if (error.status === 409 && turn) {
                return { status: 'processing', turn };
            }

            if (error.status !== undefined) {
                throw error;
            }

            if (attempt === MAX_UPLOAD_ATTEMPTS) {
                throw new Error(TEXT.upload);
            }
        }
    }
}

/**
 * The turn machine over a reactive `store` ({ state, notice, messages }) that the page renders.
 *
 * - recorder: { start({ onMaxDuration, onStopped }) → Promise<boolean>, stop(), cancel() } (see useRecorder)
 * - player:   { play(url, id) → Promise, stop() } (see useReplyPlayer)
 * - upload:   (blob, turnId) → Promise<{ turn?: string }>
 * - reload:   () → Promise<messages>, a fresh copy of the transcript from the server
 * - timers:   { setTimeout, clearTimeout }, injectable for tests
 * - newTurnId: () → string, the id of the next turn (random UUIDs; injectable for tests)
 */
export function createDebateTurns({ store, recorder, player, upload, reload, timers = globalThis, newTurnId = randomTurnId }) {
    let current = null; // the turn being answered: { id, baseline, done, timer }
    const retired = new Set(); // ids of turns that are over: their late events belong to nobody
    let speakToken = 0;
    let reloading = null;

    const pending = () => (current && !current.done ? current : null);

    function finish(turn) {
        if (!turn) {
            return;
        }

        turn.done = true;
        timers.clearTimeout(turn.timer);

        if (turn.id) {
            retired.add(turn.id);
        }
    }

    function failTurn(turn, text) {
        finish(turn);
        store.messages = withoutPending(store.messages);

        if (store.state === 'thinking') {
            store.state = 'idle';
        }

        store.notice = { tone: 'error', text };
    }

    // The event belongs to the pending turn when it names it, or when it names none. While the upload has
    // not answered yet, the first event that names a turn we have not retired tells us which one is ours.
    function claims(event) {
        if (event.turn == null) {
            return true;
        }

        if (retired.has(event.turn)) {
            return false;
        }

        const turn = pending();

        if (!turn) {
            return false;
        }

        turn.id ??= event.turn;

        return turn.id === event.turn;
    }

    function playReply(reply) {
        // Never talk over the user once they have started their next turn.
        if (store.state === 'idle' || store.state === 'thinking' || store.state === 'speaking') {
            speak(reply);
        }
    }

    function completeReply(turn, user, reply) {
        finish(turn);

        const transcript = withoutPending(store.messages);
        store.messages = upsertMessage(user ? upsertMessage(transcript, user) : transcript, reply);
        playReply(reply);
    }

    function onUserTranscribed(event) {
        const message = { id: event.message_id, role: 'user', transcript: event.transcript };

        store.messages = claims(event) ? upsertMessage(withoutPending(store.messages), message) : addMissing(store.messages, message);
    }

    function onReply(event) {
        const user = { id: event.user_message.id, role: 'user', transcript: event.user_message.transcript };
        const reply = { id: event.message_id, role: 'assistant', transcript: event.transcript, audio_url: event.audio_url };

        if (!claims(event)) {
            store.messages = addMissing(addMissing(store.messages, user), reply);

            return;
        }

        completeReply(pending(), user, reply);
    }

    function onTurnFailed(event) {
        if (claims(event)) {
            const text = { no_speech: TEXT.noSpeech, debate_ended: TEXT.ended }[event.reason] ?? TEXT.failed;
            failTurn(pending(), text);
        }
    }

    function withDeadline(promise) {
        let timer;
        const deadline = new Promise((_, reject) => {
            timer = timers.setTimeout(() => reject(new Error(TEXT.transcript)), RELOAD_TIMEOUT_MS);
        });

        return Promise.race([promise, deadline]).finally(() => timers.clearTimeout(timer));
    }

    // One reload at a time: callers that ask while one is in flight share its answer.
    function reloadOnce() {
        reloading ??= withDeadline(reload()).finally(() => {
            reloading = null;
        });

        return reloading;
    }

    /**
     * Merge a fresh transcript. Returns true when it holds the reply to the pending turn, which is then shown
     * (and played) as if the event had arrived.
     */
    function reconcile(incoming) {
        const turn = pending();
        const found = turn ? findTurnMessages(incoming, turn.baseline) : null;

        store.messages = mergeMessages(found?.user ? withoutPending(store.messages) : store.messages, incoming);

        if (!found?.reply) {
            return false;
        }

        finish(turn);
        playReply(found.reply);

        return true;
    }

    async function refresh() {
        return reconcile(await reloadOnce());
    }

    async function expire(turn) {
        if (turn.done) {
            return;
        }

        turn.timer = null;

        try {
            if (await refresh()) {
                return;
            }
        } catch {
            // Without the transcript the wait is over either way: the turn fails below.
        }

        if (!turn.done) {
            failTurn(turn, TEXT.timeout);
        }
    }

    async function sendTurn() {
        if (store.state !== 'listening') {
            return;
        }

        // Leave "listening" before awaiting, so the max-duration timer cannot send the same turn twice.
        store.state = 'thinking';
        const recording = await recorder.stop();

        if (!recording) {
            store.state = 'idle';

            return;
        }

        if (recording.duration < MIN_TURN_SECONDS) {
            store.state = 'idle';
            store.notice = { tone: 'info', text: TEXT.short };

            return;
        }

        const turn = { id: newTurnId(), baseline: latestId(store.messages), done: false, timer: null };
        current = turn;
        store.messages = [...store.messages, { id: 'pending', role: 'user', pending: true }];
        // The clock starts before the upload: the server's budget covers the whole turn, not only the answer.
        turn.timer = timers.setTimeout(() => expire(turn), REPLY_TIMEOUT_MS);

        try {
            const response = await upload(recording.blob, turn.id);

            if (!turn.done) {
                turn.id ??= response?.turn ?? null;
            }
        } catch (error) {
            if (!turn.done) {
                failTurn(turn, error.message);
            }
        }
    }

    function microphoneLost() {
        if (store.state === 'listening') {
            store.state = 'idle';
            store.notice = { tone: 'error', text: TEXT.lost };
        }
    }

    async function startListening() {
        store.state = 'starting';

        try {
            const started = await recorder.start({ onMaxDuration: sendTurn, onStopped: microphoneLost });

            if (started && store.state === 'starting') {
                store.state = 'listening';
            } else if (started) {
                recorder.cancel(); // the turn was dropped while the permission prompt was open
            }
        } catch (error) {
            if (store.state === 'starting') {
                store.state = 'idle';
                store.notice = { tone: 'error', text: error.message };
            }
        }
    }

    /**
     * The state transitions of the big button (and the Space key): start, send, or interrupt the tutor.
     * Pressing while the microphone is being opened or the tutor is thinking does nothing.
     */
    function press() {
        store.notice = null;

        if (store.state === 'idle') {
            return startListening();
        }

        if (store.state === 'listening') {
            return sendTurn();
        }

        if (store.state === 'speaking') {
            player.stop(); // barge in

            return startListening();
        }

        return undefined;
    }

    function cancel() {
        if (store.state === 'listening' || store.state === 'starting') {
            recorder.cancel();
            store.state = 'idle';
        }
    }

    function playbackNotice(error) {
        return error?.name === 'NotAllowedError' ? { tone: 'info', text: TEXT.autoplay } : { tone: 'error', text: TEXT.playback };
    }

    // A signed audio link lasts an hour: a replay after that needs the fresh link from the server.
    async function freshMessage(message) {
        try {
            const incoming = await reloadOnce();
            store.messages = mergeMessages(store.messages, incoming);

            return incoming.find((item) => item.id === message.id) ?? null;
        } catch {
            return null;
        }
    }

    async function speak(message, retried = false) {
        const token = (speakToken += 1);
        const ours = () => token === speakToken && store.state === 'speaking';

        store.state = 'speaking';

        try {
            await player.play(message.audio_url, message.id);
        } catch (error) {
            if (!ours()) {
                return;
            }

            if (error?.name !== 'NotAllowedError' && !retried) {
                const fresh = await freshMessage(message);

                if (ours() && fresh?.audio_url && fresh.audio_url !== message.audio_url) {
                    return speak(fresh, true);
                }
            }

            if (ours()) {
                store.state = 'idle';
                store.notice = playbackNotice(error);
            }

            return;
        }

        if (ours()) {
            store.state = 'idle';
        }
    }

    /**
     * Stop the pending turn's timer and ignore its events from now on (the page is going away).
     */
    function dispose() {
        finish(pending());
    }

    return {
        press,
        cancel,
        replay: speak,
        refresh,
        dispose,
        onUserTranscribed,
        onReply,
        onTurnFailed,
    };
}
