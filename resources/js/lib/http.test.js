// node --test resources/js/lib/http.test.js
import assert from 'node:assert/strict';
import { afterEach, beforeEach, test } from 'node:test';
import { postAction, postForm, postJson, requestError, sameOriginPath, xsrfToken } from './http.js';

const realFetch = globalThis.fetch;

beforeEach(() => {
    globalThis.document = { cookie: 'XSRF-TOKEN=token123' };
});

afterEach(() => {
    globalThis.fetch = realFetch;
    delete globalThis.document;
});

function answerWith(response) {
    const requests = [];
    globalThis.fetch = async (url, init) => {
        requests.push({ url, init });

        return response;
    };

    return requests;
}

test('the XSRF token is read from its cookie and decoded', () => {
    globalThis.document = { cookie: 'laravel_session=abc; XSRF-TOKEN=ab%2Fcd%3D%3D; theme=dark' };
    assert.equal(xsrfToken(), 'ab/cd==');

    globalThis.document = { cookie: 'theme=dark' };
    assert.equal(xsrfToken(), '');
});

test('a request error keeps the server message and the HTTP status', () => {
    const error = requestError({ status: 429 }, { message: 'Slow down.' });

    assert.equal(error.message, 'Slow down.');
    assert.equal(error.status, 429);
    assert.equal(requestError({ status: 503 }, {}).message, 'Request failed (HTTP 503).');
    assert.equal(requestError({ status: 500 }, null).message, 'Request failed (HTTP 500).');
});

test('a URL on another host is sent as a path on the page origin', () => {
    assert.equal(
        sameOriginPath('https://apex.example/api/debates/3/audio?x=1', 'https://app.example/debates/3'),
        '/api/debates/3/audio?x=1',
    );
    assert.equal(sameOriginPath('/api/debates/3/audio', 'https://app.example/debates/3'), '/api/debates/3/audio');
});

test('a JSON answer to a form post is returned, sent with the session and the XSRF token', async () => {
    const requests = answerWith(new Response(JSON.stringify({ status: 'processing', turn: 't1' }), { status: 202 }));

    const body = new FormData();
    assert.deepEqual(await postForm('/api/debates/3/audio', body), { status: 'processing', turn: 't1' });

    assert.equal(requests[0].init.method, 'POST');
    assert.equal(requests[0].init.credentials, 'same-origin');
    assert.equal(requests[0].init.headers['X-XSRF-TOKEN'], 'token123');
    assert.ok(requests[0].init.signal instanceof AbortSignal, 'the upload has a deadline');
});

test('a successful answer that is not JSON is an error, not an empty result', async () => {
    answerWith(new Response('<html>maintenance</html>', { status: 200 }));

    await assert.rejects(postJson('/api/lookups', {}), { status: 200, message: 'The server sent an unexpected answer.' });
});

test('a failed post reports the server message, or the status when there is none', async () => {
    answerWith(new Response(JSON.stringify({ message: 'The debate is already finished.' }), { status: 403 }));
    await assert.rejects(postForm('/api/debates/3/audio', new FormData()), { status: 403, message: 'The debate is already finished.' });

    answerWith(new Response('Server Error', { status: 500 }));
    await assert.rejects(postJson('/api/lookups', {}), { status: 500, message: 'Request failed (HTTP 500).' });
});

test('an action answered with no content is a success, whatever its body (204 has none)', async () => {
    const requests = answerWith(new Response(null, { status: 204 }));

    await postAction('/api/debates/3/messages/12/corrections/0/dismiss');

    assert.equal(requests[0].init.method, 'POST');
    assert.equal(requests[0].init.credentials, 'same-origin');
    assert.equal(requests[0].init.headers['X-XSRF-TOKEN'], 'token123');

    answerWith(new Response(JSON.stringify({ status: 'preparing' }), { status: 202 }));
    await postAction('/api/debates/3/brief');
});

test('a failed action reports the server message and status, or the status alone when there is no body', async () => {
    answerWith(new Response(JSON.stringify({ message: 'Not yours.' }), { status: 403 }));
    await assert.rejects(postAction('/api/debates/3/brief'), { status: 403, message: 'Not yours.' });

    answerWith(new Response('Server Error', { status: 500 }));
    await assert.rejects(postAction('/api/debates/3/brief'), { status: 500, message: 'Request failed (HTTP 500).' });
});
