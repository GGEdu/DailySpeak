<script setup>
defineProps({
    state: { type: String, required: true },
});

const STEPS = [
    { key: 'listening', label: 'Listening', dot: 'bg-listening', text: 'text-listening' },
    { key: 'thinking', label: 'Thinking', dot: 'bg-thinking', text: 'text-thinking' },
    { key: 'speaking', label: 'Speaking', dot: 'bg-speaking', text: 'text-speaking' },
];
</script>

<template>
    <ol class="flex items-center gap-1 rounded-full border border-white/8 bg-ink-900/70 p-1 text-xs font-medium" aria-label="Conversation state">
        <li
            v-for="step in STEPS"
            :key="step.key"
            class="flex items-center gap-1.5 rounded-full px-2.5 py-1.5 transition duration-300 sm:px-3"
            :class="state === step.key ? `bg-white/[0.06] ${step.text}` : 'text-ink-500'"
            :aria-current="state === step.key ? 'step' : undefined"
        >
            <span class="relative flex size-1.5">
                <span v-if="state === step.key" class="absolute inline-flex size-full animate-ping rounded-full opacity-75" :class="step.dot" />
                <span class="relative inline-flex size-1.5 rounded-full" :class="state === step.key ? step.dot : 'bg-ink-600'" />
            </span>
            {{ step.label }}
        </li>
    </ol>
</template>
