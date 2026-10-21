import { onBeforeUnmount, ref, shallowRef } from 'vue';
import { audioContext, createAnalyser, preferredRecordingType, recordingBlocker } from '../lib/audio.js';

// The message and the error name for each reason recording can fail; the page shows the message.
const FAILURES = {
    insecure: ['InsecureContextError', 'Voice needs a secure connection (HTTPS). Open DailySpeak over https:// and try again.'],
    unsupported: ['NotSupportedError', 'Voice recording is not supported in this browser.'],
    denied: ['NotAllowedError', 'Microphone access is blocked. Allow it in your browser to start talking.'],
};

function recordingFailure(reason) {
    const [name, message] = FAILURES[reason];
    const error = new Error(message);
    error.name = name;

    return error;
}

/**
 * Records one voice turn from the microphone (MediaRecorder) and exposes a
 * Web Audio analyser so the UI can visualise the input level while recording.
 *
 * start() resolves true once the recording runs, and false when cancel() ran while the browser was still
 * asking for permission (the microphone is released then). Calling start() again while it is pending
 * returns the same promise. If the microphone or the recorder stops by itself, onStopped() is called.
 */
export function useRecorder({ maxSeconds = 60 } = {}) {
    const recording = ref(false);
    const elapsed = ref(0);
    const analyser = shallowRef(null);

    let stream = null;
    let recorder = null;
    let source = null;
    let chunks = [];
    let timer = null;
    let startedAt = 0;
    let onLimit = null;
    let onStopped = null;
    let attempt = 0; // bumped by start() and cancel(): a permission prompt that outlives it is stale
    let pending = null;

    function start({ onMaxDuration, onStopped: stopped } = {}) {
        if (pending) {
            return pending;
        }

        if (recording.value) {
            cleanup(); // a recording still running is released, never left with the microphone open
        }

        attempt += 1;
        const opening = open(attempt, { onMaxDuration, onStopped: stopped });
        const forget = () => {
            if (pending === opening) {
                pending = null;
            }
        };

        pending = opening;
        opening.then(forget, forget);

        return opening;
    }

    async function open(id, { onMaxDuration, onStopped: stopped }) {
        const blocker = recordingBlocker({
            secureContext: window.isSecureContext,
            mediaDevices: navigator.mediaDevices,
            mediaRecorder: window.MediaRecorder,
        });

        if (blocker) {
            throw recordingFailure(blocker);
        }

        let granted;

        try {
            granted = await navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
            });
        } catch (error) {
            throw error.name === 'NotAllowedError' ? recordingFailure('denied') : error;
        }

        if (id !== attempt) {
            granted.getTracks().forEach((track) => track.stop());

            return false;
        }

        stream = granted;

        try {
            const mimeType = preferredRecordingType();
            recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);
            chunks = [];
            recorder.ondataavailable = (event) => event.data.size > 0 && chunks.push(event.data);
            recorder.onerror = () => fail();
            recorder.start();

            // The context was started by the tap that began the turn; the recording does not wait for it.
            source = audioContext().createMediaStreamSource(stream);
            analyser.value = createAnalyser();
            source.connect(analyser.value);
            stream.getTracks().forEach((track) => {
                track.onended = () => fail();
            });

            onLimit = onMaxDuration;
            onStopped = stopped;
            startedAt = performance.now();
            elapsed.value = 0;
            timer = setInterval(() => {
                elapsed.value = (performance.now() - startedAt) / 1000;

                if (elapsed.value >= maxSeconds) {
                    onLimit?.();
                }
            }, 100);

            recording.value = true;

            return true;
        } catch (error) {
            cleanup();
            throw error;
        }
    }

    // The microphone or the recorder stopped without us asking: release everything and tell the page.
    function fail() {
        if (!recording.value) {
            return;
        }

        const handler = onStopped;
        cleanup();
        handler?.();
    }

    /**
     * Stop and return the recording, with its duration in seconds (null if there was none).
     */
    function stop() {
        return new Promise((resolve) => {
            if (!recorder || recorder.state === 'inactive') {
                cleanup();
                resolve(null);

                return;
            }

            const duration = (performance.now() - startedAt) / 1000;

            recorder.onstop = () => {
                const blob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' });
                cleanup();
                resolve({ blob, duration });
            };
            recorder.stop();
        });
    }

    function cancel() {
        attempt += 1;
        pending = null;
        cleanup();
    }

    // Releases the microphone, the recorder and the analyser. Safe to call at any point, and more than once.
    function cleanup() {
        clearInterval(timer);
        source?.disconnect();

        if (recorder && recorder.state !== 'inactive') {
            recorder.onstop = null;
            recorder.onerror = null;
            recorder.stop();
        }

        stream?.getTracks().forEach((track) => {
            track.onended = null;
            track.stop();
        });

        stream = recorder = source = onLimit = onStopped = null;
        chunks = [];
        analyser.value = null;
        recording.value = false;
    }

    onBeforeUnmount(cancel);

    return { recording, elapsed, analyser, start, stop, cancel };
}
