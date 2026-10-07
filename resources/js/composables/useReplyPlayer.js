import { onBeforeUnmount, ref, shallowRef } from 'vue';
import { audioContext, createAnalyser } from '../lib/audio';

/**
 * Plays the tutor's MP3 replies through Web Audio so the UI can visualise them.
 */
export function useReplyPlayer() {
    const playing = ref(false);
    const playingId = ref(null);
    const analyser = shallowRef(null);

    let audio = null;

    /**
     * Resolves when playback finishes (or is stopped); rejects if it cannot start.
     */
    async function play(url, id = null) {
        stop();

        audio = new Audio(url);
        const element = audio;

        await audioContext().resume();
        const source = audioContext().createMediaElementSource(element);
        analyser.value = createAnalyser();
        source.connect(analyser.value);
        analyser.value.connect(audioContext().destination);

        const finished = new Promise((resolve) => {
            element.onended = resolve;
            element.onpause = resolve;
        }).finally(() => {
            source.disconnect();

            if (audio === element) {
                audio = null;
                analyser.value = null;
                playing.value = false;
                playingId.value = null;
            }
        });

        playing.value = true;
        playingId.value = id;

        try {
            await element.play();
        } catch (error) {
            element.onpause = null;
            element.onended?.();

            throw error;
        }

        return finished;
    }

    function stop() {
        audio?.pause();
    }

    onBeforeUnmount(stop);

    return { playing, playingId, analyser, play, stop };
}
