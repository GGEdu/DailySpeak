// node --test resources/js  (no dependencies: Node's own test runner)
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { broadcastAuthOptions } from './broadcasting.js';

// A stand-in for the browser's document: only the cookie is read.
function withCookie(cookie, callback) {
    globalThis.document = { cookie };

    try {
        return callback();
    } finally {
        delete globalThis.document;
    }
}

test('channel authorisation posts to the broadcasting endpoint with ajax', () => {
    const options = broadcastAuthOptions();

    assert.equal(options.endpoint, '/broadcasting/auth');
    assert.equal(options.transport, 'ajax');
});

test('the XSRF token is read from the cookie on every request, not when Echo starts', () => {
    const options = broadcastAuthOptions();

    // The cookie follows the session, so a later request must not reuse the token read at the start.
    const first = withCookie('XSRF-TOKEN=before-login', () => options.headersProvider());
    const second = withCookie('XSRF-TOKEN=after-login%3D', () => options.headersProvider());

    assert.deepEqual(first, { 'X-XSRF-TOKEN': 'before-login' });
    assert.deepEqual(second, { 'X-XSRF-TOKEN': 'after-login=' });
});

test('without the cookie no token is sent', () => {
    const headers = withCookie('theme=dark', () => broadcastAuthOptions().headersProvider());

    assert.deepEqual(headers, { 'X-XSRF-TOKEN': '' });
});
