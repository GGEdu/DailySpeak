<script setup>
import { computed, useId } from 'vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    label: { type: String, required: true },
    error: { type: String, default: null },
    // A short help text under the field, read out with it (e.g. a password's minimum length).
    hint: { type: String, default: null },
});

const model = defineModel({ type: String, default: '' });
const id = useId();
const describedBy = computed(() => [props.hint && `${id}-hint`, props.error && `${id}-error`].filter(Boolean).join(' ') || undefined);
</script>

<template>
    <div>
        <label :for="id" class="block text-sm font-medium text-ink-300">{{ label }}</label>
        <input
            :id="id"
            v-model="model"
            v-bind="$attrs"
            class="mt-1.5 block w-full rounded-lg border bg-ink-950/60 px-3 py-2.5 text-sm text-ink-100 placeholder-ink-500 transition outline-none focus:ring-2"
            :class="error ? 'border-listening/60 focus:ring-listening/60' : 'border-white/10 focus:border-speaking/60 focus:ring-speaking/60'"
            :aria-invalid="Boolean(error)"
            :aria-describedby="describedBy"
        />
        <p v-if="hint" :id="`${id}-hint`" class="mt-1.5 text-xs text-ink-400">{{ hint }}</p>
        <p v-if="error" :id="`${id}-error`" class="mt-1.5 text-xs text-listening">{{ error }}</p>
    </div>
</template>
