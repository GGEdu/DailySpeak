// node --test resources/js  (no dependencies: Node's own test runner)
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { recordingBlocker } from './audio.js';

const recorder = function MediaRecorder() {};
const mediaDevices = { getUserMedia() {} };

test('outside a secure context the browser hides the microphone API, whatever it supports', () => {
    assert.equal(recordingBlocker({ secureContext: false, mediaDevices: undefined, mediaRecorder: undefined }), 'insecure');
    assert.equal(recordingBlocker({ secureContext: false, mediaDevices, mediaRecorder: recorder }), 'insecure');
});

test('a secure page in a browser without microphone recording is unsupported', () => {
    assert.equal(recordingBlocker({ secureContext: true, mediaDevices: undefined, mediaRecorder: recorder }), 'unsupported');
    assert.equal(recordingBlocker({ secureContext: true, mediaDevices, mediaRecorder: undefined }), 'unsupported');
});

test('a secure page that can record has no blocker', () => {
    assert.equal(recordingBlocker({ secureContext: true, mediaDevices, mediaRecorder: recorder }), null);
});

test('the recorder format is the first one the browser can record, and its file extension follows it', async () => {
    const { extensionFor, preferredRecordingType } = await import('./audio.js');
    const supported = ['audio/mp4'];
    globalThis.window = { MediaRecorder: { isTypeSupported: (type) => supported.includes(type) } };

    try {
        assert.equal(preferredRecordingType(), 'audio/mp4');
        supported.length = 0;
        assert.equal(preferredRecordingType(), '');

        assert.equal(extensionFor('audio/mp4;codecs=mp4a.40.2'), 'm4a');
        assert.equal(extensionFor('audio/ogg;codecs=opus'), 'ogg');
        assert.equal(extensionFor('audio/webm;codecs=opus'), 'webm');
        assert.equal(extensionFor(''), 'webm');
    } finally {
        delete globalThis.window;
    }
});

test('waiting for the audio context gives up at the deadline when resume() never settles', async () => {
    const { resumeAudio } = await import('./audio.js');
    globalThis.AudioContext = class {
        resume() {
            return new Promise(() => {});
        }
    };

    try {
        const started = Date.now();
        await resumeAudio(20);

        assert.ok(Date.now() - started < 1_000);
    } finally {
        delete globalThis.AudioContext;
    }
});
