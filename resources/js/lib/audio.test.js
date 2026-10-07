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
