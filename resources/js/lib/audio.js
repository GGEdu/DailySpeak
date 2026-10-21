let context = null;

/**
 * One shared Web Audio context for the microphone meter and the reply playback.
 * Browsers only let it run after a user gesture, so callers resume() it on click.
 */
export function audioContext() {
    context ??= new AudioContext();

    return context;
}

/**
 * Start the shared context from a user gesture without waiting for it: on iOS resume() can stay
 * pending for good, so nothing may depend on it finishing.
 */
export function unlockAudio() {
    try {
        audioContext().resume().catch(() => {});
    } catch {
        // No Web Audio at all: the recorder and the player report that when they need it.
    }
}

/**
 * Wait for the shared context to be running, but no longer than `deadline` ms.
 */
export async function resumeAudio(deadline = 1_000) {
    let timer;
    const timeout = new Promise((resolve) => {
        timer = setTimeout(resolve, deadline);
    });

    try {
        await Promise.race([audioContext().resume(), timeout]);
    } catch {
        // Playback is attempted anyway; the player reports it if the audio cannot start.
    } finally {
        clearTimeout(timer);
    }
}

/**
 * Create an analyser for visualising a source (microphone stream or <audio> element).
 */
export function createAnalyser() {
    const analyser = audioContext().createAnalyser();
    analyser.fftSize = 256;
    analyser.smoothingTimeConstant = 0.75;

    return analyser;
}

/**
 * Why this page cannot record from the microphone, or null when it can. Browsers hide
 * navigator.mediaDevices outside secure contexts (HTTPS or localhost), so that check has to come
 * first: on plain http a supported browser would otherwise look like an unsupported one.
 */
export function recordingBlocker({ secureContext, mediaDevices, mediaRecorder }) {
    if (!secureContext) return 'insecure';
    if (!mediaDevices?.getUserMedia || !mediaRecorder) return 'unsupported';

    return null;
}

/**
 * Best recording format this browser supports that the backend (and Whisper) accept.
 */
export function preferredRecordingType() {
    const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'];

    return candidates.find((type) => window.MediaRecorder?.isTypeSupported(type)) ?? '';
}

export function extensionFor(mimeType) {
    if (mimeType.includes('mp4')) return 'm4a';
    if (mimeType.includes('ogg')) return 'ogg';

    return 'webm';
}
