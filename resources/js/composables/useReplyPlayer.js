import { onBeforeUnmount, ref, shallowRef } from 'vue';
import { audioContext, createAnalyser, resumeAudio } from '../lib/audio';

/**
 * Plays the tutor's MP3 replies through Web Audio so the UI can visualise them.
 */
export function useReplyPlayer() {
    const playing = ref(false);
    const playingId = ref(null);
    const analyser = shallowRef(null);

    let audio = null;

    /**
     * Resolves when playback finishes (or is stopped); rejects if it cannot start or breaks off.
     */
    async function play(url, id = null) {
        stop();

        const element = new Audio(url);
        audio = element;

        await resumeAudio();

        const source = audioContext().createMediaElementSource(element);
        analyser.value = createAnalyser();
        source.connect(analyser.value);
        analyser.value.connect(audioContext().destination);

        const release = () => {
            source.disconnect();

            if (audio === element) {
                audio = null;
                analyser.value = null;
                playing.value = false;
                playingId.value = null;
            }
        };

        const ended = new Promise((resolve, reject) => {
            element.onended = () => resolve();
            element.onpause = () => resolve();
            element.onerror = () => reject(new Error("The tutor's audio could not be played."));
        });
        // Handled here so that a failure before the caller awaits it is not reported as unhandled.
        ended.catch(() => {});

        playing.value = true;
        playingId.value = id;

        try {
            await element.play();
        } catch (error) {
            element.onended = element.onpause = element.onerror = null;
            release();

            throw error;
        }

        return ended.finally(release);
    }

    function stop() {
        audio?.pause();
    }

    onBeforeUnmount(stop);

    return { playing, playingId, analyser, play, stop };
}
