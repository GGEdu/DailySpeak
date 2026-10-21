// node --test resources/js/composables/useRecorder.test.js
import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { useRecorder } from './useRecorder.js';

// Vue warns when a lifecycle hook is registered outside a component; the tests call cancel() themselves.
function recorderOutsideComponent(options) {
    const warn = console.warn;
    console.warn = () => {};

    try {
        return useRecorder(options);
    } finally {
        console.warn = warn;
    }
}

const grants = [];
const realNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
const realAudioContext = globalThis.AudioContext;
const realMediaRecorder = globalThis.MediaRecorder;

function track() {
    return {
        ended: false,
        onended: null,
        stop() {
            this.ended = true;
        },
    };
}

function stream() {
    const tracks = [track()];

    return { tracks, getTracks: () => tracks };
}

// The permission prompt: the test decides when (and how) the browser answers.
function askForMicrophone() {
    return new Promise((resolve, reject) => grants.push({ resolve, reject }));
}

before(() => {
    Object.defineProperty(globalThis, 'navigator', {
        value: { mediaDevices: { getUserMedia: askForMicrophone } },
        configurable: true,
    });
    globalThis.window = {
        isSecureContext: true,
        MediaRecorder: { isTypeSupported: () => true },
        matchMedia: () => ({ matches: false }),
    };
    globalThis.AudioContext = class {
        resume() {
            return Promise.resolve();
        }

        createMediaStreamSource() {
            return { connect() {}, disconnect() {} };
        }

        createAnalyser() {
            return { connect() {}, fftSize: 0 };
        }
    };
    globalThis.MediaRecorder = class {
        constructor() {
            this.state = 'inactive';
            this.mimeType = 'audio/webm';
        }

        start() {
            this.state = 'recording';
        }

        stop() {
            this.state = 'inactive';
            setImmediate(() => this.onstop?.());
        }
    };
});

after(() => {
    if (realNavigator) {
        Object.defineProperty(globalThis, 'navigator', realNavigator);
    } else {
        delete globalThis.navigator;
    }
    globalThis.AudioContext = realAudioContext;
    globalThis.MediaRecorder = realMediaRecorder;
    delete globalThis.window;
});

test('two taps before the permission answer ask the browser once and share the result', async () => {
    const recorder = recorderOutsideComponent({});
    const first = recorder.start({});
    const second = recorder.start({});
    const before = grants.length;

    grants[before - 1].resolve(stream());

    assert.equal(await first, true);
    assert.equal(await second, true);
    assert.equal(grants.length, before, 'no second permission request');
    recorder.cancel();
});

test('cancelling while the permission prompt is open releases the microphone granted afterwards', async () => {
    const recorder = recorderOutsideComponent({});
    const pending = recorder.start({});
    recorder.cancel();

    const granted = stream();
    grants.at(-1).resolve(granted);

    assert.equal(await pending, false);
    assert.equal(granted.tracks[0].ended, true);
    assert.equal(recorder.recording.value, false);
});

test('a permission refusal reports the friendly message and records nothing', async () => {
    const recorder = recorderOutsideComponent({});
    const pending = recorder.start({});

    grants.at(-1).reject(Object.assign(new Error('denied by user'), { name: 'NotAllowedError' }));

    await assert.rejects(pending, { name: 'NotAllowedError', message: /Microphone access is blocked/ });
    assert.equal(recorder.recording.value, false);
});

test('a failure after the permission is granted releases the microphone it was given', async () => {
    const recorder = recorderOutsideComponent({});
    const realCreate = globalThis.AudioContext.prototype.createMediaStreamSource;
    globalThis.AudioContext.prototype.createMediaStreamSource = () => {
        throw new Error('no audio graph');
    };

    try {
        const pending = recorder.start({});
        const granted = stream();
        grants.at(-1).resolve(granted);

        await assert.rejects(pending, { message: 'no audio graph' });
        assert.equal(granted.tracks[0].ended, true);
        assert.equal(recorder.recording.value, false);
    } finally {
        globalThis.AudioContext.prototype.createMediaStreamSource = realCreate;
    }
});

test('the microphone ending by itself stops the recording and tells the page once', async () => {
    const recorder = recorderOutsideComponent({});
    let stopped = 0;
    const pending = recorder.start({ onStopped: () => (stopped += 1) });
    const granted = stream();
    grants.at(-1).resolve(granted);
    assert.equal(await pending, true);

    granted.tracks[0].onended();

    assert.equal(stopped, 1);
    assert.equal(granted.tracks[0].ended, true);
    assert.equal(recorder.recording.value, false);
});

test('a recording that is stopped returns its duration and releases the microphone', async () => {
    const recorder = recorderOutsideComponent({});
    const pending = recorder.start({});
    const granted = stream();
    grants.at(-1).resolve(granted);
    await pending;

    const recording = await recorder.stop();

    assert.ok(recording.blob instanceof Blob);
    assert.ok(recording.duration >= 0);
    assert.equal(granted.tracks[0].ended, true);
    assert.equal(await recorder.stop(), null, 'nothing left to stop');
});
