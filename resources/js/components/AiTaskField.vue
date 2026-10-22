<script setup>
import { computed } from 'vue';
import TextField from './TextField.vue';
import { PLATFORM, suggestedModel, suggestedVoice, TASK_LABEL } from '../lib/aiProviders.js';

const props = defineProps({
    // conversation | writing | stt | tts
    task: { type: String, required: true },
    // The usable providers of this task, from taskChoices() in lib/aiProviders.js.
    choices: { type: Array, default: () => [] },
    // { [providerId]: { conversation, writing, stt, tts, voice } } from the server.
    suggestions: { type: Object, default: () => ({}) },
    // { provider, model, voice } of this task.
    modelValue: { type: Object, required: true },
    // Errors of this task's fields: { provider, model, voice }.
    error: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:modelValue']);

const DESCRIPTION = {
    conversation: 'The tutor in the voice debate, the translations, the review of your turns and the explanation of words.',
    writing: 'The final report of each debate.',
    stt: 'Turns your speech into text.',
    tts: "The tutor's voice.",
};

const providerId = computed(() => props.modelValue.provider ?? null);
const isPlatform = computed(() => providerId.value === PLATFORM);

// A saved choice whose key has since been removed stays visible, marked, so the select does not show a blank.
const options = computed(() => {
    const current = providerId.value;

    if (!current || props.choices.some((choice) => choice.value === current)) {
        return props.choices;
    }

    return [...props.choices, { value: current, label: `${current} (not available now)` }];
});

const suggested = computed(() => ({
    model: suggestedModel(props.suggestions, providerId.value, props.task),
    voice: suggestedVoice(props.suggestions, providerId.value),
}));

// Who pays follows the provider: this is the sentence the learner needs before saving.
const payer = computed(() => {
    if (!providerId.value) {
        return 'Choose a provider. Without one, this task does not run.';
    }

    return isPlatform.value ? 'Paid by the platform.' : 'Paid by your key.';
});

const update = (patch) => emit('update:modelValue', { ...props.modelValue, ...patch });

const setField = (field, value) => update({ [field]: value === '' ? null : value });

// Switching provider brings the new provider's model along, unless the learner typed a model of their own.
const changeProvider = (event) => {
    const next = event.target.value || null;
    const previousModel = suggestedModel(props.suggestions, providerId.value, props.task);
    const keepsOwnModel = props.modelValue.model && props.modelValue.model !== previousModel;
    const nextModel = keepsOwnModel ? props.modelValue.model : suggestedModel(props.suggestions, next, props.task);

    update({ provider: next, model: nextModel });
};
</script>

<template>
    <fieldset class="rounded-2xl border border-white/8 bg-ink-900/60 p-5 sm:p-6">
        <legend class="px-1 font-semibold text-ink-100">{{ TASK_LABEL[task] }}</legend>
        <p class="text-sm text-ink-400">{{ DESCRIPTION[task] }}</p>
        <p class="mt-2 text-xs font-medium" :class="providerId ? 'text-ink-300' : 'text-amber-200'">{{ payer }}</p>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <label :for="`${task}-provider`" class="block text-sm font-medium text-ink-300">Provider</label>
                <select
                    :id="`${task}-provider`"
                    :value="providerId ?? ''"
                    class="mt-1.5 block w-full rounded-lg border bg-ink-950/60 px-3 py-2.5 text-sm text-ink-100 transition outline-none focus:ring-2"
                    :class="error.provider ? 'border-listening/60 focus:ring-listening/30' : 'border-white/10 focus:border-speaking/60 focus:ring-speaking/20'"
                    :aria-invalid="Boolean(error.provider)"
                    :aria-describedby="error.provider ? `${task}-provider-error` : undefined"
                    @change="changeProvider"
                >
                    <option value="">Choose…</option>
                    <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <p v-if="choices.length === 0" class="mt-1.5 text-xs text-ink-500">Save the key of a provider that can do this first.</p>
                <p v-if="error.provider" :id="`${task}-provider-error`" class="mt-1.5 text-xs text-listening">{{ error.provider }}</p>
            </div>

            <div>
                <TextField
                    :model-value="modelValue.model ?? ''"
                    label="Model"
                    :placeholder="isPlatform ? 'The platform’s model' : (suggested.model ?? 'Model name')"
                    :hint="isPlatform ? 'Blank uses the platform’s own model.' : null"
                    :error="error.model"
                    @update:model-value="setField('model', $event)"
                />
                <button
                    v-if="suggested.model && modelValue.model !== suggested.model"
                    type="button"
                    class="mt-1.5 inline-flex min-h-6 items-center text-xs font-medium text-thinking underline-offset-4 hover:underline"
                    @click="setField('model', suggested.model)"
                >
                    Use the suggested model: {{ suggested.model }}
                </button>
            </div>

            <div v-if="task === 'tts'">
                <TextField
                    :model-value="modelValue.voice ?? ''"
                    label="Voice"
                    :placeholder="isPlatform ? 'The platform’s voice' : (suggested.voice ?? 'Voice name')"
                    :error="error.voice"
                    @update:model-value="setField('voice', $event)"
                />
                <button
                    v-if="suggested.voice && modelValue.voice !== suggested.voice"
                    type="button"
                    class="mt-1.5 inline-flex min-h-6 items-center text-xs font-medium text-thinking underline-offset-4 hover:underline"
                    @click="setField('voice', suggested.voice)"
                >
                    Use the suggested voice: {{ suggested.voice }}
                </button>
            </div>
        </div>
    </fieldset>
</template>
