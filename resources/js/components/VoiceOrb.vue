<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    state: { type: String, required: true }, // idle | listening | thinking | speaking
    analyser: { type: Object, default: null },
    disabled: { type: Boolean, default: false },
    // What the tutor is doing now. Announced to screen readers; the button keeps its fixed name.
    label: { type: String, required: true },
});

defineEmits(['press']);

const COLORS = {
    idle: '#555c6b',
    listening: '#fb7185',
    thinking: '#a78bfa',
    speaking: '#22d3ee',
};
const BARS = 64;
// 30 fps is smooth enough for a level meter and half the work of 60.
const FRAME_INTERVAL = 1000 / 30;

const canvas = ref(null);
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
let frame = null;
let last = 0;
let levels = new Float32Array(BARS);
// Reused every frame: a new array per frame is garbage at 30 times a second.
let spectrum = new Uint8Array(0);

function readSpectrum(analyser) {
    if (spectrum.length !== analyser.frequencyBinCount) {
        spectrum = new Uint8Array(analyser.frequencyBinCount);
    }

    analyser.getByteFrequencyData(spectrum);

    return spectrum;
}

// Only a moving orb needs the loop: idle or reduced motion get one still frame per change.
function animating() {
    return props.state !== 'idle' && !reducedMotion.matches;
}

function draw(time, still) {
    const element = canvas.value;
    const context = element?.getContext('2d');

    if (!context) {
        return;
    }

    const size = element.clientWidth;
    const ratio = window.devicePixelRatio || 1;

    if (element.width !== size * ratio) {
        element.width = element.height = size * ratio;
    }

    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    context.clearRect(0, 0, size, size);

    // Live spectrum while listening/speaking; a slow synthetic wave otherwise.
    const data = props.analyser ? readSpectrum(props.analyser) : null;
    let voiceBins = 0;

    if (data) {
        // Speech lives roughly below 4 kHz: spread those bins over the circle.
        const hertzPerBin = props.analyser.context.sampleRate / 2 / data.length;
        voiceBins = Math.max(8, Math.min(data.length - 1, Math.floor(4000 / hertzPerBin)));
    }

    for (let i = 0; i < BARS; i++) {
        let target;

        if (data) {
            // Mirror low → high frequencies down both sides of the circle (skipping the DC bin).
            const position = (i < BARS / 2 ? i : BARS - i) / (BARS / 2);
            target = data[1 + Math.floor(position * (voiceBins - 1))] / 255;
        } else if (props.state === 'thinking') {
            target = 0.18 + 0.22 * Math.max(0, Math.sin(time / 260 - (i / BARS) * Math.PI * 4));
        } else {
            target = 0.08 + 0.05 * Math.sin(time / 900 + i / 3);
        }

        // A still frame shows the target itself, so an orb that stops does not freeze half-way.
        levels[i] = still ? target : levels[i] + (target - levels[i]) * 0.25;
    }

    const center = size / 2;
    const inner = size * 0.33;

    context.lineCap = 'round';
    context.lineWidth = Math.max(2, size / 110);
    context.strokeStyle = COLORS[props.state] ?? COLORS.idle;

    for (let i = 0; i < BARS; i++) {
        const angle = (i / BARS) * Math.PI * 2 - Math.PI / 2;
        const length = 3 + levels[i] * size * 0.15;
        const cos = Math.cos(angle);
        const sin = Math.sin(angle);

        context.globalAlpha = 0.35 + levels[i] * 0.65;
        context.beginPath();
        context.moveTo(center + cos * inner, center + sin * inner);
        context.lineTo(center + cos * (inner + length), center + sin * (inner + length));
        context.stroke();
    }
}

function loop(time) {
    frame = null;

    if (time - last >= FRAME_INTERVAL) {
        last = time;
        draw(time, !animating());
    }

    if (animating()) {
        schedule();
    }
}

function schedule() {
    if (frame === null) {
        frame = requestAnimationFrame(loop);
    }
}

// A state or analyser change repaints at once, even when the loop had stopped.
function repaint() {
    last = 0;
    schedule();
}

// Turning reduced motion on stops the loop at its next frame; turning it off restarts it.
function onMotionChange() {
    repaint();
}

watch(() => props.state, repaint);
watch(() => props.analyser, repaint);

onMounted(() => {
    reducedMotion.addEventListener('change', onMotionChange);
    repaint();
});

onBeforeUnmount(() => {
    cancelAnimationFrame(frame);
    reducedMotion.removeEventListener('change', onMotionChange);
});
</script>

<template>
    <div class="relative size-56 sm:size-64">
        <canvas ref="canvas" class="absolute inset-0 size-full" aria-hidden="true" />

        <span class="sr-only" aria-live="polite">{{ label }}</span>

        <div
            class="pointer-events-none absolute inset-[30%] rounded-full blur-2xl transition-colors duration-500"
            :class="{
                'bg-ink-600/30': state === 'idle',
                'bg-listening/35': state === 'listening',
                'bg-thinking/35 animate-pulse motion-reduce:animate-none': state === 'thinking',
                'bg-speaking/35': state === 'speaking',
            }"
            aria-hidden="true"
        />

        <button
            type="button"
            :disabled="disabled"
            aria-label="Talk to the tutor"
            :aria-pressed="state === 'listening'"
            class="absolute inset-[31%] grid place-items-center rounded-full border transition duration-300 focus-visible:ring-4 focus-visible:ring-ink-100 focus-visible:outline-none disabled:cursor-not-allowed"
            :class="{
                'border-white/10 bg-ink-800 hover:border-white/20 hover:bg-ink-700': state === 'idle',
                'scale-95 border-listening/50 bg-listening/15': state === 'listening',
                'border-thinking/40 bg-thinking/10': state === 'thinking',
                'border-speaking/40 bg-speaking/10 hover:bg-speaking/15': state === 'speaking',
                'opacity-50': disabled,
            }"
            @click="$emit('press')"
        >
            <!-- Mic -->
            <svg v-if="state === 'idle'" class="size-7 text-ink-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="9" y="3" width="6" height="11" rx="3" />
                <path d="M5 11a7 7 0 0 0 14 0M12 18v3" />
            </svg>
            <!-- Stop & send -->
            <span v-else-if="state === 'listening'" class="size-6 rounded-md bg-listening" />
            <!-- Thinking -->
            <span v-else-if="state === 'thinking'" class="flex gap-1.5">
                <span v-for="dot in 3" :key="dot" class="size-2 animate-bounce rounded-full bg-thinking motion-reduce:animate-none" :style="{ animationDelay: `${dot * 120}ms` }" />
            </span>
            <!-- Speaking: tap to interrupt -->
            <svg v-else class="size-7 text-speaking" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M11 5 6 9H3v6h3l5 4V5Z" />
                <path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13" />
            </svg>
        </button>
    </div>
</template>
