// node --test resources/js/lib/aiErrors.test.js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { AI_SETTINGS_LINK, aiLinksFor, errorLinks, SESSION_AI_LINKS, USAGE_LINK } from './aiErrors.js';
import { requestError } from './http.js';

test('a missing key sends the learner to the AI settings, and a monthly cap to the usage page', () => {
    assert.deepEqual(aiLinksFor('ai_not_configured'), [AI_SETTINGS_LINK]);
    assert.deepEqual(aiLinksFor('ai_cap_reached'), [USAGE_LINK]);
    assert.equal(AI_SETTINGS_LINK.href, '/settings/ai');
    assert.equal(USAGE_LINK.href, '/settings/usage');
});

test('an error without a known code needs no link', () => {
    assert.deepEqual(aiLinksFor(null), []);
    assert.deepEqual(aiLinksFor(undefined), []);
    assert.deepEqual(aiLinksFor('something_else'), []);
});

test('an error thrown by the HTTP helper carries its code into the links', () => {
    const missingKey = requestError({ status: 409 }, { message: 'Add your AI key in Settings → AI.', code: 'ai_not_configured' });
    const capped = requestError({ status: 429 }, { message: 'Used up.', code: 'ai_cap_reached' });
    const plain = requestError({ status: 500 }, { message: 'Oops.' });

    assert.deepEqual(errorLinks(missingKey), [AI_SETTINGS_LINK]);
    assert.deepEqual(errorLinks(capped), [USAGE_LINK]);
    assert.deepEqual(errorLinks(plain), []);
    assert.deepEqual(errorLinks(undefined), []);
});

test('the session error ai offers both pages, since it does not say which cause it is', () => {
    assert.deepEqual(SESSION_AI_LINKS, [AI_SETTINGS_LINK, USAGE_LINK]);
});
