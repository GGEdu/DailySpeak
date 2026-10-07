import { onBeforeUnmount, ref, shallowRef } from 'vue';
import { audioContext, createAnalyser, preferredRecordingType } from '../lib/audio';

/**
 * Records one voice turn from the microphone (MediaRecorder) and exposes a
 * Web Audio analyser so the UI can visualise the input level while recording.
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

    async function start({ onMaxDuration } = {}) {
        if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
            throw new Error('Voice recording is not supported in this browser.');
        }

        stream = await navigator.mediaDevices.getUserMedia({
            audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
        });

        const mimeType = preferredRecordingType();
        recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);
        chunks = [];
        recorder.ondataavailable = (event) => event.data.size > 0 && chunks.push(event.data);
        recorder.start();

        await audioContext().resume();
        source = audioContext().createMediaStreamSource(stream);
        analyser.value = createAnalyser();
        source.connect(analyser.value);

        onLimit = onMaxDuration;
        startedAt = performance.now();
        elapsed.value = 0;
        timer = setInterval(() => {
            elapsed.value = (performance.now() - startedAt) / 1000;

            if (elapsed.value >= maxSeconds) {
                onLimit?.();
            }
        }, 100);

        recording.value = true;
    }

    /**
     * Stop and return the recording, with its duration in seconds.
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
        if (recorder && recorder.state !== 'inactive') {
            recorder.onstop = null;
            recorder.stop();
        }

        cleanup();
    }

    function cleanup() {
        clearInterval(timer);
        source?.disconnect();
        stream?.getTracks().forEach((track) => track.stop());
        stream = recorder = source = onLimit = null;
        chunks = [];
        analyser.value = null;
        recording.value = false;
    }

    onBeforeUnmount(cancel);

    return { recording, elapsed, analyser, start, stop, cancel };
}
