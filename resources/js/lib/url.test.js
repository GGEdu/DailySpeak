// node --test resources/js  (no dependencies: Node's own test runner)
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { safeHttpUrl } from './url.js';

test('web addresses are kept as they are', () => {
    assert.equal(safeHttpUrl('https://www.bbc.com/news/world-1'), 'https://www.bbc.com/news/world-1');
    assert.equal(safeHttpUrl('http://example.com/'), 'http://example.com/');
});

test('anything that is not an http or https address is refused', () => {
    assert.equal(safeHttpUrl('javascript:alert(document.cookie)'), null);
    assert.equal(safeHttpUrl('JavaScript:alert(1)'), null);
    assert.equal(safeHttpUrl('data:text/html,<script>alert(1)</script>'), null);
    assert.equal(safeHttpUrl('ftp://example.com/file'), null);
});

test('relative and malformed values are refused, and so are missing ones', () => {
    assert.equal(safeHttpUrl('//example.com/path'), null);
    assert.equal(safeHttpUrl('/feed'), null);
    assert.equal(safeHttpUrl('not a url'), null);
    assert.equal(safeHttpUrl(''), null);
    assert.equal(safeHttpUrl(null), null);
    assert.equal(safeHttpUrl(undefined), null);
    assert.equal(safeHttpUrl(42), null);
});
