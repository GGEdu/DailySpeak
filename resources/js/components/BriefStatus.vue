<script setup>
defineProps({
    status: { type: String, default: null },
    reason: { type: String, default: null },
    busy: { type: Boolean, default: false },
    error: { type: String, default: null },
});

defineEmits(['retry']);
</script>

<template>
    <!-- Waiting for the brief: the question is generated with it and the debate opens after. -->
    <div v-if="status === 'preparing'" class="flex items-center gap-3 rounded-2xl border border-thinking/20 bg-thinking/[0.06] p-5" role="status">
        <span class="flex gap-1.5" aria-hidden="true">
            <span v-for="dot in 3" :key="dot" class="size-2 animate-bounce rounded-full bg-thinking motion-reduce:animate-none" :style="{ animationDelay: `${dot * 120}ms` }" />
        </span>
        <p class="text-sm text-ink-200">Preparing your debate brief…</p>
    </div>

    <!-- The daily AI allowance is used up: nothing to retry until it resets. -->
    <div v-else-if="status === 'failed' && reason === 'budget'" class="rounded-2xl border border-white/8 bg-ink-900/60 p-5" role="alert">
        <p class="text-sm text-ink-200">Today's AI allowance is used up, so this brief can't be prepared now. Come back tomorrow.</p>
    </div>

    <div v-else-if="status === 'failed'" class="rounded-2xl border border-listening/25 bg-listening/10 p-5" role="alert">
        <p class="text-sm text-listening">We couldn't prepare the brief for this story.</p>
        <button
            type="button"
            :disabled="busy"
            class="mt-3 rounded-lg bg-ink-100 px-3.5 py-2 text-sm font-semibold text-ink-950 transition hover:bg-white disabled:cursor-wait disabled:opacity-50"
            @click="$emit('retry')"
        >
            Try again
        </button>
        <p v-if="error" class="mt-2 text-xs text-listening">{{ error }}</p>
    </div>
</template>
