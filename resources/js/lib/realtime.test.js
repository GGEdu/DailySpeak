// node --test resources/js/lib/realtime.test.js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { echoIsConfigured } from '@laravel/echo-vue';
import { configureRealtime } from './realtime.js';

// Stands in for the library: configureEcho keeps its options and echoIsConfigured reports them.
function fakeEcho() {
    const calls = [];
    let configured = false;

    return {
        calls,
        configure(options) {
            calls.push(options);
            configured = true;
        },
        isConfigured: () => configured,
    };
}

test('the debate page sets Echo up once, however many times it is mounted', () => {
    const echo = fakeEcho();

    configureRealtime(echo);
    configureRealtime(echo); // a second visit mounts the page component again

    assert.deepEqual(echo.calls, [{ broadcaster: 'reverb' }]);
});

test('with the real library, configuring twice leaves Echo configured', () => {
    configureRealtime();
    configureRealtime();

    assert.equal(echoIsConfigured(), true);
});
