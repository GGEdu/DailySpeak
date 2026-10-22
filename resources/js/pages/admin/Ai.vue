<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import SectionTabs from '../../components/SectionTabs.vue';
import TextField from '../../components/TextField.vue';
import AppLayout from '../../layouts/AppLayout.vue';
import { postJson } from '../../lib/http.js';
import { PLATFORM_TABS } from '../../lib/tabs.js';

defineOptions({ layout: AppLayout });

// The platform's connection, from App\Services\AiSettings::settings(). Each model line is { value, default }.
const props = defineProps({
    settings: {
        type: Object,
        default: () => ({ base_url: { value: null, default: null }, api_key: { set: false, last4: null, source: null }, models: {} }),
    },
});

const MODEL_LINES = [
    { key: 'conversation', label: 'Conversation', hint: 'The tutor, translations, turn reviews and word analysis.' },
    { key: 'writing', label: 'Writing', hint: 'News summaries, debate briefs and fluency reports.' },
    { key: 'stt', label: 'Speech-to-text' },
    { key: 'tts', label: 'Text-to-speech' },
    { key: 'tts_voice', label: 'Voice' },
];

const modelDefault = (key) => props.settings.models?.[key]?.default ?? '';

// The address and the models go together: a blank field goes back to its default in .env.
const form = useForm({
    base_url: props.settings.base_url?.value ?? '',
    models: Object.fromEntries(MODEL_LINES.map(({ key }) => [key, props.settings.models?.[key]?.value ?? ''])),
});

const save = () => form
    .transform((data) => ({
        base_url: data.base_url || null,
        models: Object.fromEntries(Object.entries(data.models).map(([key, model]) => [key, model || null])),
    }))
    .put('/admin/ai', { preserveScroll: true });

// The key is never shown again: the page only has its last four characters.
const keyForm = useForm({ api_key: '' });

const saveKey = () => keyForm.put('/admin/ai/key', {
    preserveScroll: true,
    onSuccess: () => keyForm.reset('api_key'),
});

const forgetKey = () => {
    if (confirm('Remove the key saved here? The key in .env is used from then on.')) {
        router.delete('/admin/ai/key', { preserveScroll: true });
    }
};

const keyStatus = () => {
    if (props.settings.api_key?.source === 'admin') {
        return `Key ending in ••••${props.settings.api_key.last4}, saved here.`;
    }

    if (props.settings.api_key?.source === 'env') {
        return 'The key in .env is used. A key saved here replaces it.';
    }

    return 'No key is set. The platform cannot reach the AI service.';
};

// The check looks at the settings saved on this page, not at what is typed in it.
const testing = ref(false);
const testResult = ref(null);

const test = async () => {
    testing.value = true;
    testResult.value = null;

    try {
        testResult.value = await postJson('/admin/ai/test', {});
    } catch (error) {
        testResult.value = { ok: false, message: error.message };
    } finally {
        testing.value = false;
    }
};
</script>

<template>
    <Head title="AI connection" />

    <div class="mx-auto max-w-3xl">
        <header>
            <p class="text-sm font-medium text-ink-400">Platform</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink-100">AI connection</h1>
            <p class="mt-3 text-ink-400">
                The owner’s connection. It pays for what all users share (news summaries and debate summaries), and for the
                users the owner allows to use it. A blank field goes back to its value in .env.
            </p>
        </header>

        <SectionTabs class="mt-8" :tabs="PLATFORM_TABS" label="Platform sections" />

        <form class="mt-10 space-y-6 rounded-2xl border border-white/8 bg-ink-900/60 p-6" novalidate @submit.prevent="save">
            <h2 class="text-sm font-semibold text-ink-100">Address</h2>
            <TextField
                v-model="form.base_url"
                label="Service address"
                type="url"
                placeholder="https://…"
                :hint="settings.base_url?.default ? `Blank uses “${settings.base_url.default}” from .env.` : 'Blank leaves it unset.'"
                :error="form.errors.base_url"
            />

            <h2 class="pt-2 text-sm font-semibold text-ink-100">Models</h2>
            <p class="-mt-4 text-xs text-ink-500">The name each task asks the service for. A blank field uses the one in .env.</p>

            <div class="grid gap-5 sm:grid-cols-2">
                <div v-for="line in MODEL_LINES" :key="line.key">
                    <TextField
                        v-model="form.models[line.key]"
                        :label="line.label"
                        :placeholder="modelDefault(line.key) || 'Not set'"
                        :hint="line.hint"
                        :error="form.errors[`models.${line.key}`]"
                    />
                </div>
            </div>

            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-xl bg-ink-100 px-5 py-2.5 text-sm font-semibold text-ink-950 transition hover:bg-white disabled:opacity-50"
                >
                    Save connection
                </button>
            </div>
        </form>

        <section class="mt-8 rounded-2xl border border-white/8 bg-ink-900/60 p-6" aria-labelledby="key-heading">
            <h2 id="key-heading" class="text-sm font-semibold text-ink-100">Key</h2>
            <p class="mt-2 text-sm text-ink-300">{{ keyStatus() }}</p>

            <form class="mt-5 space-y-4" novalidate @submit.prevent="saveKey">
                <TextField
                    v-model="keyForm.api_key"
                    label="New key"
                    type="password"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    hint="Stored encrypted. It is checked before it is saved and is never shown again."
                    :error="keyForm.errors.api_key"
                />
                <div class="flex flex-wrap gap-2">
                    <button
                        type="submit"
                        :disabled="keyForm.processing || keyForm.api_key === ''"
                        class="rounded-xl bg-ink-100 px-4 py-2 text-sm font-semibold text-ink-950 transition hover:bg-white disabled:opacity-50"
                    >
                        Save key
                    </button>
                    <button
                        type="button"
                        :disabled="settings.api_key?.source !== 'admin'"
                        class="rounded-xl px-4 py-2 text-sm font-medium text-listening transition hover:bg-listening/10 disabled:opacity-40"
                        @click="forgetKey"
                    >
                        Remove the key saved here
                    </button>
                </div>
            </form>
        </section>

        <section class="mt-8 rounded-2xl border border-white/8 bg-ink-900/60 p-6" aria-labelledby="test-heading">
            <h2 id="test-heading" class="text-sm font-semibold text-ink-100">Test</h2>
            <p class="mt-2 text-sm text-ink-400">Asks the service for its model list with the settings saved on this page.</p>

            <div class="mt-4 flex flex-wrap items-center gap-4">
                <button
                    type="button"
                    :disabled="testing"
                    class="rounded-xl border border-white/10 px-4 py-2 text-sm font-medium text-ink-200 transition hover:bg-white/5 disabled:opacity-40"
                    @click="test"
                >
                    {{ testing ? 'Testing…' : 'Test connection' }}
                </button>
                <p v-if="testResult" role="status" class="text-sm" :class="testResult.ok ? 'text-emerald-300' : 'text-listening'">{{ testResult.message }}</p>
            </div>
        </section>
    </div>
</template>
