// node --test resources/js  (no dependencies: Node's own test runner)
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { activeLookup, closeLookup, noteInput, openLookup } from './lookup.js';

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
