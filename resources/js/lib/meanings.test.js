// node --test resources/js/lib/meanings.test.js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createMeanings } from './meanings.js';

const explanation = (id, definition = 'A subtle difference.') => ({ id, analysis: { definition } });

function fresh() {
    const store = { analyses: {}, failed: {} };

    return { store, meanings: createMeanings(store) };
}

test('an answer with the analysis of the word shows it, and the request is not a failure', () => {
    const { store, meanings } = fresh();

    meanings.ask(7);
    meanings.answer(7, explanation(7));
    meanings.finish(7);

    assert.deepEqual(store.analyses, { 7: { definition: 'A subtle difference.' } });
    assert.equal(meanings.has(7), true);
    assert.equal(store.failed[7], false);
});

test('a request that ends without an answer is a failure, not a meaning that is still loading', () => {
    const { store, meanings } = fresh();

    meanings.ask(7);
    meanings.finish(7);

    assert.equal(meanings.has(7), false);
    assert.equal(store.failed[7], true);
});

test('an answer about another word, or about a word that is no longer the user’s, is a failure too', () => {
    const { store, meanings } = fresh();

    meanings.ask(7);
    meanings.answer(7, { id: null, analysis: null });
    meanings.finish(7);
    assert.equal(store.failed[7], true);

    meanings.ask(8);
    meanings.answer(8, explanation(9));
    meanings.finish(8);
    assert.equal(store.failed[8], true);
    assert.equal(meanings.has(8), false);
});

test('an analysis that came back empty is a failure: there is nothing to show', () => {
    const { store, meanings } = fresh();

    meanings.ask(7);
    meanings.answer(7, { id: 7, analysis: null });
    meanings.finish(7);

    assert.equal(meanings.has(7), false);
    assert.equal(store.failed[7], true);
});

test('a retry clears the failure while it is in flight, and a later answer shows the meaning', () => {
    const { store, meanings } = fresh();

    meanings.ask(7);
    meanings.finish(7);
    assert.equal(store.failed[7], true);

    meanings.ask(7);
    assert.equal(store.failed[7], false);

    meanings.answer(7, explanation(7));
    meanings.finish(7);
    assert.equal(meanings.has(7), true);
    assert.equal(store.failed[7], false);
});

test('a failure of one word does not touch the others', () => {
    const { store, meanings } = fresh();

    meanings.ask(1);
    meanings.answer(1, explanation(1));
    meanings.finish(1);
    meanings.ask(2);
    meanings.finish(2);

    assert.equal(meanings.has(1), true);
    assert.equal(store.failed[1], false);
    assert.equal(store.failed[2], true);
});
