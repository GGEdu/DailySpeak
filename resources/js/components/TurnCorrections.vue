<script setup>
import { computed } from 'vue';
import { visibleCorrections } from '../lib/transcript.js';

const props = defineProps({
    corrections: { type: Array, default: () => [] },
});

defineEmits(['dismiss']);

const shown = computed(() => visibleCorrections({ corrections: props.corrections }));
</script>

<template>
    <!-- Written under the learner's message only: these are never read aloud. -->
    <ul v-if="shown.length" aria-label="Corrections" class="w-full space-y-2">
        <li v-for="item in shown" :key="item.index" class="rounded-xl border border-white/8 bg-ink-900/60 px-3.5 py-2.5 text-sm">
            <p class="text-ink-400">
                <span class="sr-only">Instead of: </span>
                <span class="line-through decoration-listening/60" data-selectable>{{ item.original }}</span>
            </p>
            <p class="mt-0.5 text-ink-100">
                <span class="font-semibold text-speaking">Better: </span>
                <span class="font-medium" data-selectable>{{ item.better }}</span>
            </p>
            <p v-if="item.note" class="mt-1 text-xs text-ink-400" data-selectable>{{ item.note }}</p>
            <button
                type="button"
                class="mt-1.5 inline-flex min-h-6 items-center text-xs font-medium text-ink-400 underline-offset-4 transition hover:text-ink-100 hover:underline"
                :aria-label="`I didn't say that: ${item.original}`"
                @click="$emit('dismiss', item.index)"
            >
                I didn't say that
            </button>
        </li>
    </ul>
</template>
