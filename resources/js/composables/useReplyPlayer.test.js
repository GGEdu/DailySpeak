// node --test resources/js/composables/useReplyPlayer.test.js
import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { useReplyPlayer } from './useReplyPlayer.js';

// Vue warns when a lifecycle hook is registered outside a component; the tests never unmount.
function playerOutsideComponent() {
    const warn = console.warn;
    console.warn = () => {};

    try {
        return useReplyPlayer();
    } finally {
        console.warn = warn;
    }
}

let elements = [];
const flush = () => new Promise((resolve) => setImmediate(resolve));
const realAudio = globalThis.Audio;
const realAudioContext = globalThis.AudioContext;

before(() => {
    globalThis.Audio = class {
        constructor(url) {
            this.url = url;
            this.playing = null;
            elements.push(this);
        }

        play() {
            return this.playing ?? Promise.resolve();
        }

        pause() {
            this.onpause?.();
        }
    };
    globalThis.AudioContext = class {
        resume() {
            return Promise.resolve();
        }

        createMediaElementSource() {
            return { connect() {}, disconnect() {} };
        }

        createAnalyser() {
            return { connect() {}, disconnect() {}, fftSize: 0 };
        }

        get destination() {
            return {};
        }
    };
});

after(() => {
    globalThis.Audio = realAudio;
    globalThis.AudioContext = realAudioContext;
});

test('playback resolves when the reply ends, and then nothing is playing', async () => {
    const player = playerOutsideComponent();
    const playing = player.play('https://files.test/reply.mp3', 13);
    await flush();

    assert.equal(player.playing.value, true);
    assert.equal(player.playingId.value, 13);
    elements.at(-1).onended();
    await playing;

    assert.equal(player.playing.value, false);
    assert.equal(player.playingId.value, null);
});

test('a reply that breaks off while playing rejects instead of leaving the page in "Speaking"', async () => {
    const player = playerOutsideComponent();
    const playing = player.play('https://files.test/reply.mp3', 13);
    await flush();

    elements.at(-1).onerror();

    await assert.rejects(playing, { message: "The tutor's audio could not be played." });
    assert.equal(player.playing.value, false);
});

test('a play() refused by the browser rejects with its name and clears the player', async () => {
    const player = playerOutsideComponent();
    elements = [];
    const original = globalThis.Audio;
    globalThis.Audio = class extends original {
        play() {
            return Promise.reject(Object.assign(new Error('autoplay'), { name: 'NotAllowedError' }));
        }
    };

    try {
        await assert.rejects(player.play('https://files.test/reply.mp3', 13), { name: 'NotAllowedError' });
        assert.equal(player.playing.value, false);
        assert.equal(player.playingId.value, null);
    } finally {
        globalThis.Audio = original;
    }
});
