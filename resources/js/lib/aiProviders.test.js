// node --test resources/js/lib/aiProviders.test.js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    capabilitiesLabel,
    hasKey,
    isVerified,
    keySummary,
    PLATFORM,
    providersFor,
    suggestedModel,
    suggestedVoice,
    taskChoices,
} from './aiProviders.js';

const providers = [
    { id: 'openai', label: 'OpenAI', capabilities: ['text', 'stt', 'tts'], key: { set: true, last4: 'abcd', verified_at: '2026-10-20T10:00:00Z' } },
    { id: 'groq', label: 'Groq', capabilities: ['text', 'stt'], key: { set: false, last4: null, verified_at: null } },
    { id: 'deepseek', label: 'DeepSeek', capabilities: ['text'], key: { set: true, last4: '9f2e', verified_at: null } },
];

test('a provider counts for a task only with its capability and a saved key', () => {
    assert.deepEqual(
        providersFor('conversation', providers).map((provider) => provider.id),
        ['openai', 'deepseek'],
    );
    assert.deepEqual(
        providersFor('stt', providers).map((provider) => provider.id),
        ['openai'],
    );
    assert.deepEqual(
        providersFor('tts', providers).map((provider) => provider.id),
        ['openai'],
    );
    assert.deepEqual(providersFor('writing', []), []);
});

test('the choices are the usable providers, then the platform when it is allowed', () => {
    assert.deepEqual(taskChoices('conversation', providers, false), [
        { value: 'openai', label: 'OpenAI' },
        { value: 'deepseek', label: 'DeepSeek' },
    ]);

    assert.deepEqual(taskChoices('stt', providers, true), [
        { value: 'openai', label: 'OpenAI' },
        { value: PLATFORM, label: 'Platform' },
    ]);

    assert.deepEqual(taskChoices('tts', [], false), []);
    assert.deepEqual(taskChoices('tts', undefined, true), [{ value: PLATFORM, label: 'Platform' }]);
});

test('the suggested model and voice come from the provider, and the platform has none', () => {
    const suggestions = {
        openai: { conversation: 'gpt-4o-mini', writing: 'gpt-4o', stt: 'whisper-1', tts: 'gpt-4o-mini-tts', voice: 'alloy' },
    };

    assert.equal(suggestedModel(suggestions, 'openai', 'conversation'), 'gpt-4o-mini');
    assert.equal(suggestedModel(suggestions, 'openai', 'stt'), 'whisper-1');
    assert.equal(suggestedVoice(suggestions, 'openai'), 'alloy');
    assert.equal(suggestedModel(suggestions, 'deepseek', 'conversation'), null);
    assert.equal(suggestedModel(suggestions, PLATFORM, 'conversation'), null);
    assert.equal(suggestedModel(suggestions, null, 'conversation'), null);
    assert.equal(suggestedModel(undefined, 'openai', 'conversation'), null);
    assert.equal(suggestedVoice(suggestions, PLATFORM), null);
});

test('a key is described by its last four characters, never by the key', () => {
    assert.equal(keySummary({ set: true, last4: 'abcd', verified_at: null }), 'Key ending in ••••abcd');
    assert.equal(keySummary({ set: true, last4: null, verified_at: null }), 'Key saved');
    assert.equal(keySummary({ set: false, last4: null, verified_at: null }), 'No key saved');
    assert.equal(keySummary(undefined), 'No key saved');
});

test('a saved key tells whether it was verified', () => {
    assert.equal(hasKey(providers[0]), true);
    assert.equal(hasKey(providers[1]), false);
    assert.equal(hasKey(undefined), false);
    assert.equal(isVerified(providers[0]), true);
    assert.equal(isVerified(providers[2]), false);
});

test('capabilities are named in words', () => {
    assert.equal(capabilitiesLabel(['text', 'stt', 'tts']), 'Text · Speech-to-text · Text-to-speech');
    assert.equal(capabilitiesLabel([]), '');
});
