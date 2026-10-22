// node --test resources/js/lib/transcript.test.js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { addMissing, findTurnMessages, latestId, mergeMessages, upsertMessage, withoutPending } from './transcript.js';

const user = (id, transcript = 'hello') => ({ id, role: 'user', transcript });
const tutor = (id, transcript = 'why?') => ({ id, role: 'assistant', transcript, audio_url: `https://files.test/${id}.mp3` });

test('a fresh transcript fills the gaps in server order and keeps the placeholder last', () => {
    const shown = [user(40), tutor(41), { id: 'pending', role: 'user', pending: true }];
    const incoming = [user(38), tutor(39), user(40), tutor(41), user(42)];

    const merged = mergeMessages(shown, incoming);

    assert.deepEqual(
        merged.map((message) => message.id),
        [38, 39, 40, 41, 42, 'pending'],
    );
});

test('a fresh copy of a known message updates its fields (a renewed audio link)', () => {
    const merged = mergeMessages([tutor(41, 'why?')], [{ ...tutor(41, 'why?'), audio_url: 'https://files.test/renewed.mp3' }]);

    assert.equal(merged.length, 1);
    assert.equal(merged[0].audio_url, 'https://files.test/renewed.mp3');
});

test('upsert replaces a message with the same id and appends a new one', () => {
    const messages = [user(10, 'draft')];

    assert.deepEqual(upsertMessage(messages, user(10, 'final')), [user(10, 'final')]);
    assert.deepEqual(upsertMessage(messages, tutor(11)), [user(10, 'draft'), tutor(11)]);
});

test('addMissing never duplicates a message', () => {
    const messages = [tutor(11)];

    assert.equal(addMissing(messages, tutor(11)), messages);
    assert.deepEqual(addMissing(messages, user(12)), [tutor(11), user(12)]);
});

test('withoutPending removes the placeholder and nothing else', () => {
    const messages = [user(10), { id: 'pending', role: 'user', pending: true }];

    assert.deepEqual(withoutPending(messages), [user(10)]);
    assert.equal(withoutPending([user(10)]).length, 1);
});

test('latestId is the highest server id, ignoring the placeholder', () => {
    assert.equal(latestId([]), 0);
    assert.equal(latestId([user(7), tutor(9), { id: 'pending', role: 'user', pending: true }]), 9);
});

// The server marks a user message with the voice turn it belongs to, and a tutor reply with the message it answers.
const turnUser = (id, turn, transcript = 'my answer') => ({ ...user(id, transcript), turn });
const answer = (id, userId, transcript = 'and?') => ({ ...tutor(id, transcript), reply_to_id: userId });

test('a turn has no reply until the tutor message that answers its user message exists', () => {
    const messages = [user(10), tutor(11), turnUser(12, 't1'), { id: 'pending', role: 'user', pending: true }];

    assert.deepEqual(findTurnMessages(messages, 't1'), { user: turnUser(12, 't1'), reply: null });
});

test('the reply of a turn is the tutor message that answers its user message', () => {
    const messages = [user(10), tutor(11), turnUser(12, 't1'), answer(13, 12)];

    assert.deepEqual(findTurnMessages(messages, 't1'), { user: turnUser(12, 't1'), reply: answer(13, 12) });
});

test('a late reply to an earlier turn is not the reply of the turn being waited for', () => {
    const messages = [user(10), tutor(11), turnUser(12, 't1'), answer(13, 12), turnUser(14, 't2')];

    assert.deepEqual(findTurnMessages(messages, 't2'), { user: turnUser(14, 't2'), reply: null });
});

test('messages without a turn never match a turn, not even with a missing turn id', () => {
    assert.deepEqual(findTurnMessages([user(10), tutor(11)], 't1'), { user: null, reply: null });
    assert.deepEqual(findTurnMessages([user(10), { ...user(12), turn: null }], null), { user: null, reply: null });
});
