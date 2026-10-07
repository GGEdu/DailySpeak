<script setup>
defineProps({
    message: { type: Object, required: true },
    playing: { type: Boolean, default: false },
});

defineEmits(['play']);
</script>

<template>
    <div class="flex" :class="message.role === 'user' ? 'justify-end' : 'justify-start'">
        <div
            class="group relative max-w-[85%] rounded-2xl px-4 py-3 text-[15px] leading-relaxed"
            :class="message.role === 'user' ? 'rounded-br-md bg-ink-700/70 text-ink-100' : 'rounded-bl-md border border-speaking/15 bg-speaking/[0.06] text-ink-100'"
        >
            <p class="mb-1 text-[11px] font-semibold tracking-wide uppercase" :class="message.role === 'user' ? 'text-ink-400' : 'text-speaking/80'">
                {{ message.role === 'user' ? 'You' : 'Tutor' }}
            </p>

            <p v-if="message.pending" class="flex items-center gap-1 py-1.5" aria-label="Transcribing">
                <span v-for="dot in 3" :key="dot" class="size-1.5 animate-pulse rounded-full bg-ink-400" :style="{ animationDelay: `${dot * 150}ms` }" />
            </p>
            <p v-else>{{ message.transcript }}</p>

            <button
                v-if="message.audio_url && !message.pending"
                type="button"
                class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-ink-400 transition hover:text-ink-100"
                @click="$emit('play')"
            >
                <svg class="size-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <rect v-if="playing" x="6" y="5" width="4" height="14" rx="1" />
                    <rect v-if="playing" x="14" y="5" width="4" height="14" rx="1" />
                    <path v-else d="M8 5.5v13a1 1 0 0 0 1.5.86l10.5-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5Z" />
                </svg>
                {{ playing ? 'Stop' : 'Replay' }}
            </button>
        </div>
    </div>
</template>
