// node --test resources/js/lib/debateTurn.test.js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createDebateTurns, OPENING_WAIT_MS, randomTurnId, REPLY_TIMEOUT_MS, TEXT, uploadTurn } from './debateTurn.js';

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
}

// Timers that only fire when the test moves the clock.
function fakeClock() {
    let now = 0;
    let nextId = 1;
    const timers = new Map();

    return {
        setTimeout(callback, ms) {
            const id = nextId;
            nextId += 1;
            timers.set(id, { at: now + ms, callback });

            return id;
        },
        clearTimeout(id) {
            timers.delete(id);
        },
        tick(ms) {
            now += ms;

            for (const [id, timer] of [...timers].sort(([, a], [, b]) => a.at - b.at)) {
                if (timer.at <= now && timers.has(id)) {
                    timers.delete(id);
                    timer.callback();
                }
            }
        },
        get pending() {
            return timers.size;
        },
    };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

const user = (id, transcript = 'I think so.') => ({ id, role: 'user', transcript });
const tutor = (id, transcript = 'Why?') => ({ id, role: 'assistant', transcript, audio_url: `https://files.test/${id}.mp3` });
// The transcript from the server names the voice turn of a user message, and the message a reply answers.
const turnUser = (id, turn) => ({ ...user(id), turn });
const replyTo = (id, userId, transcript = 'Why?') => ({ ...tutor(id, transcript), reply_to_id: userId });
const userEvent = (id, turn) => ({ message_id: id, transcript: 'I think so.', turn });
const replyEvent = (id, userId, turn) => ({
    message_id: id,
    transcript: 'Why?',
    audio_url: `https://files.test/${id}.mp3`,
    user_message: { id: userId, transcript: 'I think so.' },
    turn,
});

function harness({ messages = [user(10), tutor(11)], duration = 2, turnIds = ['t1', 't2', 't3'], awaitingOpening = false } = {}) {
    const clock = fakeClock();
    const store = { state: 'idle', notice: null, messages, opening: null };
    const starts = [];
    const plays = [];
    const uploads = [];
    const reloads = [];
    const dismisses = [];
    const calls = { cancel: 0, stop: 0, stopPlayback: 0 };
    const recorder = {
        start(options) {
            const grant = deferred();
            starts.push({ options, ...grant });

            return grant.promise;
        },
        async stop() {
            calls.stop += 1;

            return { blob: new Blob(['audio']), duration };
        },
        cancel() {
            calls.cancel += 1;
        },
    };
    const player = {
        playingId: { value: null },
        play(url, id) {
            const playback = deferred();
            plays.push({ url, id, ...playback });

            return playback.promise;
        },
        stop() {
            calls.stopPlayback += 1;
        },
    };
    const turns = createDebateTurns({
        store,
        recorder,
        player,
        upload: (blob, turn) => {
            const answer = deferred();
            answer.turn = turn;
            uploads.push(answer);

            return answer.promise;
        },
        reload: () => {
            const incoming = deferred();
            reloads.push(incoming);

            return incoming.promise;
        },
        dismiss: (messageId, index) => {
            const answer = deferred();
            dismisses.push({ messageId, index, ...answer });

            return answer.promise;
        },
        timers: clock,
        newTurnId: () => turnIds.shift(),
        awaitingOpening,
    });

    return { store, turns, clock, starts, plays, uploads, reloads, dismisses, calls };
}

// Tap, grant the microphone, tap to send: leaves the page waiting for the tutor.
async function sendTurn(h) {
    h.turns.press();
    h.starts.at(-1).resolve(true);
    await flush();
    h.turns.press();
    await flush();
}

test('a tap opens the microphone once: a second tap while the permission prompt is open does nothing', async () => {
    const h = harness();

    h.turns.press();
    assert.equal(h.store.state, 'starting');
    h.turns.press();
    assert.equal(h.starts.length, 1);

    h.starts[0].resolve(true);
    await flush();

    assert.equal(h.store.state, 'listening');
});

test('cancelling while the permission prompt is open sends nothing and leaves the page idle', async () => {
    const h = harness();

    h.turns.press();
    h.turns.cancel();
    h.starts[0].resolve(false); // the recorder releases the stream it was granted
    await flush();

    assert.equal(h.store.state, 'idle');
    assert.equal(h.calls.cancel, 1);
    assert.equal(h.uploads.length, 0);
});

test('a recording shorter than the minimum is discarded with a hint', async () => {
    const h = harness({ duration: 0.2 });

    h.turns.press();
    h.starts[0].resolve(true);
    await flush();
    h.turns.press();
    await flush();

    assert.equal(h.store.state, 'idle');
    assert.equal(h.store.notice.text, TEXT.short);
    assert.equal(h.uploads.length, 0);
});

test('the turn is uploaded once, and the tutor reply plays before the page goes back to idle', async () => {
    const h = harness();

    await sendTurn(h);
    h.turns.press(); // the send is already in progress
    assert.equal(h.store.state, 'thinking');
    assert.equal(h.uploads.length, 1);
    assert.equal(h.store.messages.at(-1).pending, true);

    h.uploads[0].resolve({ status: 'processing', turn: 't1' });
    await flush();
    h.turns.onUserTranscribed(userEvent(12, 't1'));
    h.turns.onReply(replyEvent(13, 12, 't1'));

    assert.equal(h.store.state, 'speaking');
    assert.equal(h.plays[0].url, 'https://files.test/13.mp3');
    assert.deepEqual(
        h.store.messages.map((message) => message.id),
        [10, 11, 12, 13],
    );

    h.plays[0].resolve();
    await flush();

    assert.equal(h.store.state, 'idle');
    assert.equal(h.clock.pending, 0, 'the reply timer is cleared once the reply arrives');
});

test('the max-duration timer and a tap both send the turn once', async () => {
    const h = harness();

    h.turns.press();
    h.starts[0].resolve(true);
    await flush();
    h.starts[0].options.onMaxDuration();
    h.starts[0].options.onMaxDuration();
    await flush();

    assert.equal(h.uploads.length, 1);
});

test('events of another turn leave the state and the audio alone, and add their messages once', async () => {
    const h = harness();
    await sendTurn(h);
    h.uploads[0].resolve({ status: 'processing', turn: 't1' });
    await flush();

    h.turns.onUserTranscribed(userEvent(20, 't0'));
    h.turns.onReply(replyEvent(21, 20, 't0'));
    h.turns.onReply(replyEvent(21, 20, 't0'));

    assert.equal(h.store.state, 'thinking');
    assert.equal(h.plays.length, 0);
    assert.deepEqual(
        h.store.messages.filter((message) => message.id === 21).map((message) => message.transcript),
        ['Why?'],
    );
    assert.ok(h.store.messages.some((message) => message.id === 20));
    assert.ok(h.store.messages.some((message) => message.pending), 'the placeholder of the current turn stays');
});

test('an event without a turn (an older server) belongs to the pending turn', async () => {
    const h = harness();
    await sendTurn(h);

    h.turns.onReply(replyEvent(13, 12, undefined));

    assert.equal(h.store.state, 'speaking');
    assert.equal(h.plays.length, 1);
});

test('a late event of a turn that already timed out is not taken for the next turn, even before its upload answers', async () => {
    const h = harness();
    await sendTurn(h);
    h.uploads[0].resolve({ status: 'processing', turn: 't1' });
    await flush();
    h.clock.tick(REPLY_TIMEOUT_MS);
    h.reloads[0].resolve([user(10), tutor(11)]);
    await flush();
    assert.equal(h.store.state, 'idle');

    await sendTurn(h);
    h.turns.onReply(replyEvent(13, 12, 't1'));

    assert.equal(h.store.state, 'thinking');
    assert.equal(h.plays.length, 0);
});

test('the reply can arrive before the upload has answered: the turn id is known from the start', async () => {
    const h = harness({ turnIds: ['t9'] });
    await sendTurn(h);

    h.turns.onReply(replyEvent(13, 12, 't9'));
    assert.equal(h.store.state, 'speaking');

    h.uploads[0].resolve({ status: 'processing', turn: 't9' });
    await flush();
    assert.equal(h.store.state, 'speaking');
    assert.equal(h.plays.length, 1);
});

test('a failure of the pending turn ends the wait with its message, and failures of other turns are ignored', async () => {
    const h = harness();
    await sendTurn(h);
    h.uploads[0].resolve({ status: 'processing', turn: 't1' });
    await flush();

    h.turns.onTurnFailed({ reason: 'failed', turn: 't0' });
    assert.equal(h.store.state, 'thinking');
    assert.equal(h.store.notice, null);

    h.turns.onTurnFailed({ reason: 'no_speech', turn: 't1' });

    assert.equal(h.store.state, 'idle');
    assert.deepEqual(h.store.notice, { tone: 'error', text: TEXT.noSpeech });
    assert.equal(h.store.messages.some((message) => message.pending), false);
    assert.equal(h.clock.pending, 0);
});

const transcriptIds = (h) => h.store.messages.filter((message) => !message.pending).map((message) => message.id);

// The server deletes the turn's message when it gives up on the turn: the client must not keep showing it.
for (const [reason, text] of [
    ['processing_failed', TEXT.failed],
    ['debate_ended', TEXT.ended],
]) {
    test(`a turn that ends with ${reason} takes its message off the transcript, as the server did`, async () => {
        const h = harness();
        await sendTurn(h);
        h.uploads[0].resolve({ status: 'processing', turn: 't1' });
        await flush();
        h.turns.onUserTranscribed(userEvent(12, 't1'));
        assert.deepEqual(transcriptIds(h), [10, 11, 12]);

        h.turns.onTurnFailed({ reason, turn: 't1' });

        assert.equal(h.store.state, 'idle');
        assert.deepEqual(h.store.notice, { tone: 'error', text });
        assert.deepEqual(transcriptIds(h), [10, 11]);
        assert.equal(h.store.messages.some((message) => message.pending), false);
    });
}

test('a refresh that no longer has the message of the pending turn takes it off the transcript', async () => {
    const h = harness();
    await sendTurn(h);
    h.uploads[0].resolve({ status: 'processing', turn: 't1' });
    await flush();
    h.turns.onUserTranscribed(userEvent(12, 't1'));

    const merged = h.turns.refresh();
    h.reloads[0].resolve([user(10), tutor(11)]);

    assert.equal(await merged, false);
    assert.deepEqual(transcriptIds(h), [10, 11]);
});

test('a copy taken before a turn event does not decide what was deleted', async () => {
    const h = harness();
    await sendTurn(h);
    h.uploads[0].resolve({ status: 'processing', turn: 't1' });
    await flush();

    const merged = h.turns.refresh();
    h.turns.onUserTranscribed(userEvent(12, 't1'));
    h.reloads[0].resolve([user(10), tutor(11)]);
    await merged;

    assert.deepEqual(transcriptIds(h), [10, 11, 12]);
});

test('a copy taken before the failure does not bring the deleted message back', async () => {
    const h = harness();
    await sendTurn(h);
    h.uploads[0].resolve({ status: 'processing', turn: 't1' });
    await flush();
    h.turns.onUserTranscribed(userEvent(12, 't1'));

    const merged = h.turns.refresh();
    h.turns.onTurnFailed({ reason: 'processing_failed', turn: 't1' });
    h.reloads[0].resolve([user(10), tutor(11), user(12)]);
    await merged;

    assert.deepEqual(transcriptIds(h), [10, 11]);
});

test('a refresh takes off messages after the turn started, never the ones before it', async () => {
    const h = harness();
    await sendTurn(h);
    h.uploads[0].resolve({ status: 'processing', turn: 't1' });
    await flush();

    const merged = h.turns.refresh();
    h.reloads[0].resolve([tutor(11)]);
    await merged;

    assert.deepEqual(transcriptIds(h), [10, 11]);
});

test('a reply that has not come after 130 s is asked for once, and the turn fails if it is not there', async () => {
    const h = harness();
    await sendTurn(h);
    h.uploads[0].resolve({ status: 'processing', turn: 't1' });
    await flush();

    h.clock.tick(REPLY_TIMEOUT_MS - 1);
    assert.equal(h.reloads.length, 0);

    h.clock.tick(1);
    assert.equal(h.reloads.length, 1);
    h.reloads[0].resolve([user(10), tutor(11)]);
    await flush();

    assert.equal(h.store.state, 'idle');
    assert.deepEqual(h.store.notice, { tone: 'error', text: TEXT.timeout });
    assert.equal(h.store.messages.some((message) => message.pending), false);
});

test('at 130 s a reply the socket never delivered is shown instead of the error', async () => {
    const h = harness();
    await sendTurn(h);
    h.uploads[0].resolve({ status: 'processing', turn: 't1' });
    await flush();

    h.clock.tick(REPLY_TIMEOUT_MS);
    h.reloads[0].resolve([user(10), tutor(11), turnUser(12, 't1'), replyTo(13, 12, 'Go on.')]);
    await flush();

    assert.equal(h.store.notice, null);
    assert.equal(h.store.state, 'speaking');
    assert.equal(h.plays[0].url, 'https://files.test/13.mp3');
    assert.ok(h.store.messages.some((message) => message.id === 12 && message.transcript === 'I think so.'));
});

test('a refresh while a reply is pending plays it, and otherwise only merges the transcript', async () => {
    const h = harness();
    await sendTurn(h);
    h.uploads[0].resolve({ status: 'processing', turn: 't1' });
    await flush();

    const merged = h.turns.refresh();
    h.reloads[0].resolve([user(10), tutor(11), turnUser(12, 't1')]);
    assert.equal(await merged, false);
    assert.equal(h.store.state, 'thinking');
    assert.ok(h.store.messages.some((message) => message.id === 12));
    assert.equal(h.store.messages.some((message) => message.pending), false);

    const reply = h.turns.refresh();
    h.reloads[1].resolve([user(10), tutor(11), turnUser(12, 't1'), replyTo(13, 12)]);
    assert.equal(await reply, true);
    assert.equal(h.store.state, 'speaking');
});

test('a late reply to an earlier turn, brought by a refresh, is not the reply of the turn being waited for', async () => {
    const h = harness({ turnIds: ['t1', 't2'] });
    await sendTurn(h);
    h.uploads[0].resolve({ status: 'processing', turn: 't1' });
    await flush();
    h.clock.tick(REPLY_TIMEOUT_MS);
    h.reloads[0].resolve([user(10), tutor(11)]);
    await flush();
    assert.equal(h.store.state, 'idle', 'the first turn gave up');

    await sendTurn(h);
    h.uploads[1].resolve({ status: 'processing', turn: 't2' });
    await flush();

    // The reply to t1 arrived late: its ids are after the baseline of t2, but it answers t1, not t2.
    const refreshed = h.turns.refresh();
    h.reloads[1].resolve([user(10), tutor(11), turnUser(12, 't1'), replyTo(13, 12)]);

    assert.equal(await refreshed, false);
    assert.equal(h.store.state, 'thinking');
    assert.equal(h.plays.length, 0);
});

test('a failed upload is retried by uploadTurn; a second failure ends the turn with the message', async () => {
    const h = harness();
    h.turns.press();
    h.starts[0].resolve(true);
    await flush();
    h.turns.press();
    await flush();

    h.uploads[0].reject(new Error('The debate is already finished.'));
    await flush();

    assert.equal(h.store.state, 'idle');
    assert.deepEqual(h.store.notice, { tone: 'error', text: 'The debate is already finished.' });
    assert.equal(h.clock.pending, 0);
});

test('a replay whose link has expired fetches the fresh link and plays it once', async () => {
    const h = harness();

    const replay = h.turns.replay(tutor(11));
    assert.equal(h.store.state, 'speaking');
    h.plays[0].reject(Object.assign(new Error('Unsupported'), { name: 'NotSupportedError' }));
    await flush();

    assert.equal(h.reloads.length, 1);
    h.reloads[0].resolve([user(10), { ...tutor(11), audio_url: 'https://files.test/fresh.mp3' }]);
    await flush();

    assert.equal(h.plays[1].url, 'https://files.test/fresh.mp3');
    h.plays[1].resolve();
    await replay;
    assert.equal(h.store.state, 'idle');
    assert.equal(h.store.notice, null);
});

test('a replay that fails with the same link shows the playback error', async () => {
    const h = harness();

    const replay = h.turns.replay(tutor(11));
    h.plays[0].reject(Object.assign(new Error('Unsupported'), { name: 'NotSupportedError' }));
    await flush();
    h.reloads[0].resolve([user(10), tutor(11)]);
    await replay;

    assert.equal(h.plays.length, 1);
    assert.equal(h.store.state, 'idle');
    assert.deepEqual(h.store.notice, { tone: 'error', text: TEXT.playback });
});

test('a blocked autoplay explains what to press and does not look for a fresh link', async () => {
    const h = harness();

    const replay = h.turns.replay(tutor(11));
    h.plays[0].reject(Object.assign(new Error('Blocked'), { name: 'NotAllowedError' }));
    await replay;

    assert.equal(h.reloads.length, 0);
    assert.equal(h.store.state, 'idle');
    assert.deepEqual(h.store.notice, { tone: 'info', text: TEXT.autoplay });
});

test('an older playback that ends does not reset the state of the newer one', async () => {
    const h = harness();

    const first = h.turns.replay(tutor(11));
    const second = h.turns.replay(tutor(12));
    h.plays[0].resolve();
    await first;
    assert.equal(h.store.state, 'speaking');

    h.plays[1].resolve();
    await second;
    assert.equal(h.store.state, 'idle');
});

test('a tap while the tutor speaks interrupts it and starts the next turn', async () => {
    const h = harness();
    const replay = h.turns.replay(tutor(11));

    h.turns.press();
    assert.equal(h.calls.stopPlayback, 1);
    assert.equal(h.store.state, 'starting');

    h.plays[0].resolve();
    await replay;
    assert.equal(h.store.state, 'starting', 'the interrupted playback does not reset the state');

    h.starts[0].resolve(true);
    await flush();
    assert.equal(h.store.state, 'listening');
});

test('the microphone stopping by itself ends the turn with a warning', async () => {
    const h = harness();

    h.turns.press();
    h.starts[0].resolve(true);
    await flush();
    h.starts[0].options.onStopped();

    assert.equal(h.store.state, 'idle');
    assert.deepEqual(h.store.notice, { tone: 'error', text: TEXT.lost });
});

test('a permission error is shown and leaves the page idle', async () => {
    const h = harness();

    h.turns.press();
    h.starts[0].reject(Object.assign(new Error('Microphone access is blocked.'), { name: 'NotAllowedError' }));
    await flush();

    assert.equal(h.store.state, 'idle');
    assert.deepEqual(h.store.notice, { tone: 'error', text: 'Microphone access is blocked.' });
});

test('dispose stops the reply timer and ignores late events', async () => {
    const h = harness();
    await sendTurn(h);
    h.uploads[0].resolve({ status: 'processing', turn: 't1' });
    await flush();

    h.turns.dispose();
    assert.equal(h.clock.pending, 0);
    h.turns.onReply(replyEvent(13, 12, 't1'));
    assert.equal(h.plays.length, 0);
});

test('uploadTurn sends the same recording again after a dropped connection', async () => {
    const bodies = [];
    const send = async (url, body) => {
        bodies.push(body);

        if (bodies.length === 1) {
            throw new TypeError('Failed to fetch');
        }

        return { status: 'processing', turn: 't1' };
    };

    assert.deepEqual(await uploadTurn('/api/debates/3/audio', new Blob(['audio']), 't1', send), { status: 'processing', turn: 't1' });
    assert.equal(bodies.length, 2);
    assert.equal(bodies[0].get('audio').name, 'turn.webm');
    assert.equal(bodies[1].get('audio').size, bodies[0].get('audio').size);
    // The same id both times: the server stores and answers the turn once.
    assert.deepEqual([bodies[0].get('turn'), bodies[1].get('turn')], ['t1', 't1']);
});

test('an answer from the server is final: uploadTurn does not repeat it', async () => {
    let calls = 0;
    const send = async () => {
        calls += 1;

        throw Object.assign(new Error('Too many turns today.'), { status: 429 });
    };

    await assert.rejects(uploadTurn('/api/debates/3/audio', new Blob(['audio']), 't1', send), { message: 'Too many turns today.' });
    assert.equal(calls, 1);
});

test('two dropped connections end in a plain message about the connection', async () => {
    let calls = 0;
    const send = async () => {
        calls += 1;

        throw new TypeError('Failed to fetch');
    };

    await assert.rejects(uploadTurn('/api/debates/3/audio', new Blob(['audio']), 't1', send), { message: TEXT.upload });
    assert.equal(calls, 2);
});

test('a 409 means the first upload of this turn is still being stored: the turn is on its way', async () => {
    let calls = 0;
    const send = async () => {
        calls += 1;

        if (calls === 1) {
            throw new TypeError('Failed to fetch');
        }

        throw Object.assign(new Error('This turn is still being received.'), { status: 409 });
    };

    assert.deepEqual(await uploadTurn('/api/debates/3/audio', new Blob(['audio']), 't1', send), { status: 'processing', turn: 't1' });
    assert.equal(calls, 2);
});

test('each turn uploads with its own id, chosen before the upload', async () => {
    const h = harness();
    await sendTurn(h);

    assert.equal(h.uploads[0].turn, 't1');
    h.uploads[0].resolve({ status: 'processing', turn: 't1' });
    await flush();
    h.turns.onReply(replyEvent(13, 12, 't1'));
    await flush();
    h.plays.at(-1).resolve();
    await flush();

    await sendTurn(h);
    assert.equal(h.uploads[1].turn, 't2');
});

test('a turn sent after the debate ended says so', async () => {
    const h = harness();
    await sendTurn(h);

    h.turns.onTurnFailed({ reason: 'debate_ended', turn: 't1' });

    assert.equal(h.store.state, 'idle');
    assert.deepEqual(h.store.notice, { tone: 'error', text: TEXT.ended });
});

test('randomTurnId gives a version 4 UUID, with or without crypto.randomUUID', () => {
    const shape = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

    assert.match(randomTurnId(), shape);
    assert.match(randomTurnId({ getRandomValues: (bytes) => bytes.fill(255) }), shape);
});

// --- The tutor's opening (a guided debate) --------------------------------------------------------------------

const opening = (id = 11, audio = `https://files.test/${id}.mp3`) => ({ message_id: id, transcript: 'Is it fair to ban phones?', audio_url: audio });

test('a guided debate waits for the tutor\'s opening: a tap does not open the microphone', () => {
    const h = harness({ messages: [], awaitingOpening: true });

    assert.equal(h.store.opening, 'waiting');
    h.turns.press();
    assert.equal(h.starts.length, 0);
    assert.equal(h.store.state, 'idle');
});

test('the wait ends after 30 seconds without an opening, so the learner can still speak', () => {
    const h = harness({ messages: [], awaitingOpening: true });

    h.clock.tick(OPENING_WAIT_MS - 1);
    h.turns.press();
    assert.equal(h.starts.length, 0);

    h.clock.tick(1);
    assert.equal(h.store.opening, null);
    h.turns.press();
    assert.equal(h.starts.length, 1);
});

test('the opening arriving is shown, spoken once, and then the learner can answer', async () => {
    const h = harness({ messages: [], awaitingOpening: true });

    h.turns.onOpened(opening(11));
    h.turns.onOpened(opening(11)); // the same event again must not speak it twice

    assert.deepEqual(h.store.messages, [{ id: 11, role: 'assistant', transcript: 'Is it fair to ban phones?', audio_url: 'https://files.test/11.mp3' }]);
    assert.equal(h.store.opening, null);
    assert.equal(h.plays.length, 1);
    assert.equal(h.plays[0].url, 'https://files.test/11.mp3');

    h.plays[0].resolve();
    await flush();
    assert.equal(h.store.state, 'idle');

    h.turns.press();
    assert.equal(h.starts.length, 1);
});

test('an opening without audio is text only: nothing is played and the learner can answer', async () => {
    const h = harness({ messages: [], awaitingOpening: true });

    h.turns.onOpened(opening(11, null));

    assert.equal(h.store.messages[0].transcript, 'Is it fair to ban phones?');
    assert.equal(h.plays.length, 0);
    assert.equal(h.store.state, 'idle');
    assert.equal(h.store.notice, null);

    h.turns.press();
    assert.equal(h.starts.length, 1);
});

test('a fresh copy of the transcript that brings the opening ends the wait and speaks it', async () => {
    const h = harness({ messages: [], awaitingOpening: true });

    h.turns.refresh();
    h.reloads[0].resolve([tutor(11, 'Is it fair to ban phones?')]);
    await flush();

    assert.equal(h.store.opening, null);
    assert.equal(h.plays.length, 1);
    assert.equal(h.store.messages.length, 1);
});

test('the wait is armed again when the brief becomes ready, and counts 30 seconds from then', () => {
    const h = harness({ messages: [] });

    assert.equal(h.store.opening, null);
    h.turns.waitForOpening();
    assert.equal(h.store.opening, 'waiting');

    h.clock.tick(OPENING_WAIT_MS - 1);
    assert.equal(h.store.opening, 'waiting');
    h.clock.tick(1);
    assert.equal(h.store.opening, null);
});

test('no wait is armed once the transcript has any message', () => {
    const h = harness({ messages: [user(10), tutor(11)] });

    h.turns.waitForOpening();

    assert.equal(h.store.opening, null);
});

// --- Corrections, written under the learner's message -------------------------------------------------------

const correction = (index, dismissed = false) => ({ index, original: 'I is sure', better: "I'm sure", note: null, dismissed });
const review = (messageId, corrections) => ({ debate_id: 3, turn: 't9', message_id: messageId, corrections });

test('a review attaches its corrections to the learner message, and nothing is spoken', () => {
    const h = harness();

    h.turns.onLearnerTurnReviewed(review(10, [correction(0)]));

    assert.deepEqual(h.store.messages[0].corrections, [correction(0)]);
    assert.equal(h.plays.length, 0);
    assert.equal(h.store.state, 'idle');
    assert.equal(h.reloads.length, 0);
});

test('a review of a message the transcript does not have asks for a fresh copy', () => {
    const h = harness();

    h.turns.onLearnerTurnReviewed(review(99, [correction(0)]));

    assert.equal(h.reloads.length, 1);
    assert.equal(h.store.messages.some((message) => message.id === 99), false);
});

test('a dismissed correction is hidden at once, and the server is told which one', async () => {
    const h = harness();
    h.turns.onLearnerTurnReviewed(review(10, [correction(0), correction(1)]));

    const pending = h.turns.dismissCorrection(10, 0);

    assert.equal(h.store.messages[0].corrections[0].dismissed, true);
    assert.equal(h.store.messages[0].corrections[1].dismissed, false);
    assert.deepEqual(
        h.dismisses.map((call) => [call.messageId, call.index]),
        [[10, 0]],
    );

    h.dismisses[0].resolve();
    await pending;
    assert.equal(h.store.notice, null);
    assert.equal(h.store.messages[0].corrections[0].dismissed, true);
});

test('a dismissal that fails shows the correction again with a notice', async () => {
    const h = harness();
    h.turns.onLearnerTurnReviewed(review(10, [correction(0)]));

    const pending = h.turns.dismissCorrection(10, 0);
    h.dismisses[0].reject(new Error('Server Error'));
    await pending;

    assert.equal(h.store.messages[0].corrections[0].dismissed, false);
    assert.deepEqual(h.store.notice, { tone: 'error', text: TEXT.dismiss });
});

test('a reload while a dismissal is in flight does not show the correction again', async () => {
    const h = harness();
    h.turns.onLearnerTurnReviewed(review(10, [correction(0)]));

    const pending = h.turns.dismissCorrection(10, 0);
    h.turns.refresh();
    h.reloads[0].resolve([{ ...user(10), corrections: [correction(0)] }, tutor(11)]);
    await flush();

    assert.equal(h.store.messages[0].corrections[0].dismissed, true);

    h.dismisses[0].resolve();
    await pending;
});

test('a reload that predates the review keeps the corrections already shown', async () => {
    const h = harness();
    h.turns.onLearnerTurnReviewed(review(10, [correction(0)]));

    h.turns.refresh();
    h.reloads[0].resolve([{ ...user(10), corrections: null }, tutor(11)]);
    await flush();

    assert.deepEqual(h.store.messages[0].corrections, [correction(0)]);
});
