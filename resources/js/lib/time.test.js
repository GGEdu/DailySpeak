// node --test resources/js  (no dependencies: Node's own test runner)
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { clock, dayLabel, timeAgo } from './time.js';

const HOUR = 60 * 60 * 1000;
const DAY = 24 * HOUR;

test('timeAgo phrases the distance from now in the largest unit that fits', () => {
    assert.equal(timeAgo(new Date(Date.now() - 3 * HOUR)), '3 hours ago');
    assert.equal(timeAgo(new Date(Date.now() - 20 * 60 * 1000)), '20 minutes ago');
    assert.equal(timeAgo(new Date(Date.now() - 3 * DAY)), '3 days ago');
    assert.equal(timeAgo(new Date(Date.now() + 2 * DAY)), 'in 2 days');
});

test('timeAgo says "yesterday" and "just now" at the edges', () => {
    assert.equal(timeAgo(new Date(Date.now() - 25 * HOUR)), 'yesterday');
    assert.equal(timeAgo(new Date(Date.now() - 10 * 1000)), 'just now');
    assert.equal(timeAgo(new Date(Date.now())), 'just now');
});

test('timeAgo accepts the ISO strings the server sends', () => {
    assert.equal(timeAgo(new Date(Date.now() - 3 * HOUR).toISOString()), '3 hours ago');
});

test('dayLabel names today and yesterday, and spells out any other day', () => {
    const now = new Date();
    const yesterday = new Date();
    yesterday.setDate(now.getDate() - 1);

    assert.equal(dayLabel(now), 'Today');
    assert.equal(dayLabel(now.toISOString()), 'Today');
    assert.equal(dayLabel(yesterday), 'Yesterday');
    assert.equal(dayLabel(new Date(2020, 9, 5)), 'Monday 5 October');
});

test('clock formats seconds as minutes and zero-padded seconds', () => {
    assert.equal(clock(0), '0:00');
    assert.equal(clock(5), '0:05');
    assert.equal(clock(65.9), '1:05');
    assert.equal(clock(600), '10:00');
    assert.equal(clock(3599), '59:59');
});
