// node --test resources/js  (no dependencies: Node's own test runner)
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { activeLookup, clearExplanations, closeLookup, explain, friendlyError, noteInput, openLookup } from './lookup.js';

test('a lookup opened after keyboard input takes focus, so Tab reaches its buttons', () => {
    noteInput('keyboard');
    openLookup('nuance', 'The nuance matters.', null);

    assert.equal(activeLookup.value.focus, true);
    closeLookup();
});

test('a lookup opened after mouse or touch input leaves the focus where it is', () => {
    noteInput('pointer');
    openLookup('nuance', 'The nuance matters.', null);

    assert.equal(activeLookup.value.focus, false);
    closeLookup();
});

test('an explicit focus option wins over the last input', () => {
    noteInput('pointer');
    openLookup('nuance', null, null, { focus: true });

    assert.equal(activeLookup.value.focus, true);
    closeLookup();
});

function failure(status, message = 'Server said no.') {
    return Object.assign(new Error(message), { status });
}

test('a rate limit and an expired session get a message the learner can act on', () => {
    assert.match(friendlyError(failure(429)), /Wait a moment/);
    assert.match(friendlyError(failure(401)), /Reload the page/);
    assert.match(friendlyError(failure(419)), /Reload the page/);
});

test('other failures show the server message', () => {
    assert.equal(friendlyError(failure(500, 'Translation unavailable.')), 'Translation unavailable.');
});

test('explanations are kept per user and level, and forgotten when the session ends', async () => {
    const realFetch = globalThis.fetch;
    const realDocument = globalThis.document;
    let requests = 0;

    globalThis.document = { cookie: '' };
    globalThis.fetch = async () => {
        requests++;

        return new Response(JSON.stringify({ translation: 'matiz' }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    };

    try {
        await explain('nuance', 'The nuance matters.', 'user-1:B2');
        await explain('Nuance', 'The nuance matters.', 'user-1:B2');
        assert.equal(requests, 1, 'the same word in the same context is answered from memory');

        await explain('nuance', 'The nuance matters.', 'user-2:B2');
        assert.equal(requests, 2, 'another user gets their own explanation');

        clearExplanations();
        await explain('nuance', 'The nuance matters.', 'user-1:B2');
        assert.equal(requests, 3, 'after logging out nothing is kept');
    } finally {
        globalThis.fetch = realFetch;
        globalThis.document = realDocument;
        clearExplanations();
    }
});
