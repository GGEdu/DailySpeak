<script setup>
import { useId } from 'vue';

defineOptions({ inheritAttrs: false });

defineProps({
    label: { type: String, required: true },
    error: { type: String, default: null },
});

const model = defineModel({ type: String, default: '' });
const id = useId();
</script>

<template>
    <div>
        <label :for="id" class="block text-sm font-medium text-ink-300">{{ label }}</label>
        <input
            :id="id"
            v-model="model"
            v-bind="$attrs"
            class="mt-1.5 block w-full rounded-lg border bg-ink-950/60 px-3 py-2.5 text-sm text-ink-100 placeholder-ink-500 transition outline-none focus:ring-2"
            :class="error ? 'border-listening/60 focus:ring-listening/30' : 'border-white/10 focus:border-speaking/60 focus:ring-speaking/20'"
            :aria-invalid="Boolean(error)"
            :aria-describedby="error ? `${id}-error` : undefined"
        />
        <p v-if="error" :id="`${id}-error`" class="mt-1.5 text-xs text-listening">{{ error }}</p>
    </div>
</template>
