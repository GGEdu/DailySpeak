// node --test resources/js  (no dependencies: Node's own test runner)
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isVocabulary, normalise, sentenceAt } from './text.js';

function context(text, selected, from = 0) {
    const start = text.indexOf(selected, from);

    return sentenceAt(text, start, start + selected.length);
}

test('normalise trims spaces and the punctuation a double-click picks up', () => {
    assert.equal(normalise('  “powering through,”  '), 'powering through');
    assert.equal(normalise('grew.'), 'grew');
    assert.equal(normalise('Brontë'), 'Brontë');
    assert.equal(normalise('€5 billion'), '€5 billion');
});

test('only words and short expressions count as vocabulary', () => {
    assert.ok(isVocabulary('nuance'));
    assert.ok(isVocabulary('take it with a pinch'));
    assert.ok(!isVocabulary('far too many words to be vocabulary here'));
    assert.ok(!isVocabulary('1984'));
    assert.ok(!isVocabulary('a'.repeat(61)));
});

test('the context is the sentence around the selection', () => {
    const text = 'Sales fell. The economy grew faster than expected. Prices rose.';

    assert.equal(context(text, 'grew'), 'The economy grew faster than expected.');
    assert.equal(context(text, 'Sales'), 'Sales fell.');
    assert.equal(context(text, 'rose'), 'Prices rose.');
});

test('abbreviations and quotes do not end a sentence', () => {
    assert.equal(context('The U.S. economy grew faster than expected last year.', 'grew'), 'The U.S. economy grew faster than expected last year.');
    assert.equal(context('Exports to the U.S. Rose while imports fell. Next one.', 'Exports'), 'Exports to the U.S. Rose while imports fell.');
    assert.equal(context('Yesterday Dr. Smith said it was fine. Then he left.', 'fine'), 'Yesterday Dr. Smith said it was fine.');
    assert.equal(context('"Stop!" he said loudly. Everyone froze.', 'loudly'), '"Stop!" he said loudly.');
});

test('a selection that includes the full stop stays in its own sentence', () => {
    const text = 'The economy grew. Prices rose.';
    const start = text.indexOf('grew.');

    assert.equal(sentenceAt(text, start, start + 'grew.'.length), 'The economy grew.');
});

test('long text without punctuation is cut around the selection', () => {
    const text = `${'word '.repeat(150)}target ${'more '.repeat(150)}`;
    const sentence = context(text, 'target');

    assert.ok(sentence.length <= 500);
    assert.ok(sentence.includes('target'));
});
