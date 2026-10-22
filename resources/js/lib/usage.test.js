// node --test resources/js/lib/usage.test.js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { capLabel, capUsage, formatCount, monthLabel, paidByLabel, resultLabel, taskName, tokensLabel } from './usage.js';

test('counts are grouped, and a count the server did not measure is a dash', () => {
    assert.equal(formatCount(1234567), '1,234,567');
    assert.equal(formatCount(0), '0');
    assert.equal(formatCount(null), '—');
    assert.equal(formatCount(undefined), '—');
    assert.equal(formatCount(Number.NaN), '—');
});

test('the month is named in English from YYYY-MM', () => {
    assert.equal(monthLabel('2026-10'), 'October 2026');
    assert.equal(monthLabel('2026-01'), 'January 2026');
    assert.equal(monthLabel('October'), 'October');
    assert.equal(monthLabel(null), '');
});

test('the cap reads as a limit or as none', () => {
    assert.equal(capLabel(null), 'No limit');
    assert.equal(capLabel(undefined), 'No limit');
    assert.equal(capLabel(1), '1 call a month');
    assert.equal(capLabel(200), '200 calls a month');
});

test('the share of the cap used is capped at 100, and absent without a cap', () => {
    assert.equal(capUsage(50, 200), 25);
    assert.equal(capUsage(250, 200), 100);
    assert.equal(capUsage(0, 200), 0);
    assert.equal(capUsage(10, null), null);
    assert.equal(capUsage(10, 0), null);
    assert.equal(capUsage(undefined, 4), 0);
});

test('who paid, what the task is called, and the outcome of a call', () => {
    assert.equal(paidByLabel('user'), 'Your key');
    assert.equal(paidByLabel('platform'), 'Platform');
    assert.equal(taskName('stt'), 'Speech-to-text');
    assert.equal(taskName('unknown'), 'unknown');
    assert.equal(resultLabel(true), 'Done');
    assert.equal(resultLabel(false), 'Failed');
});

test('tokens are shown as in and out, with a dash for what was not counted', () => {
    assert.equal(tokensLabel(1200, 350), '1,200 in · 350 out');
    assert.equal(tokensLabel(null, 350), '— in · 350 out');
    assert.equal(tokensLabel(null, null), '—');
});
