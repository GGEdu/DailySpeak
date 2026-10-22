<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import TextField from './TextField.vue';
import { capabilitiesLabel, isVerified, keySummary } from '../lib/aiProviders.js';
import { postJson } from '../lib/http.js';
import { timeAgo } from '../lib/time.js';

const props = defineProps({
    // { id, label, capabilities, key: { set, last4, verified_at } }, one entry of the server's providers.
    provider: { type: Object, required: true },
});

// The field starts empty every time: a saved key is never sent back to the page.
const form = useForm({ key: '' });

// The answer of the last check ({ ok, message }), or null before the first one.
const check = ref(null);
const checking = ref(false);

const save = () => form.put(`/settings/ai/keys/${props.provider.id}`, {
    preserveScroll: true,
    onSuccess: () => form.reset('key'),
});

// Tests the key saved for this provider (the server asks the provider for its model list).
const testKey = async () => {
    checking.value = true;
    check.value = null;

    try {
        check.value = await postJson(`/settings/ai/test/${props.provider.id}`, {});
    } catch (error) {
        check.value = { ok: false, message: error.message };
    } finally {
        checking.value = false;
    }
};

const remove = () => {
    if (confirm(`Remove the key for ${props.provider.label}? Tasks that use it will need another provider.`)) {
        router.delete(`/settings/ai/keys/${props.provider.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <article class="flex flex-col rounded-2xl border border-white/8 bg-ink-900/60 p-5 sm:p-6" :aria-labelledby="`provider-${provider.id}`">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 :id="`provider-${provider.id}`" class="font-semibold text-ink-100">{{ provider.label }}</h3>
                <p class="mt-1 text-xs text-ink-400">{{ capabilitiesLabel(provider.capabilities) }}</p>
            </div>
            <p class="text-xs font-medium" :class="provider.key.set ? 'text-emerald-300' : 'text-ink-500'">{{ keySummary(provider.key) }}</p>
        </div>

        <p v-if="provider.key.set" class="mt-2 text-xs text-ink-500">
            {{ isVerified(provider) ? `Verified ${timeAgo(provider.key.verified_at)}` : 'Not verified yet' }}
        </p>

        <form class="mt-4 space-y-4" @submit.prevent="save">
            <TextField
                v-model="form.key"
                label="API key"
                type="password"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                :hint="provider.key.set ? 'Saved keys are never shown. Paste a new one to replace it.' : 'Stored encrypted. It is checked before it is saved.'"
                :error="form.errors.key"
            />

            <div class="flex flex-wrap items-center gap-2 text-sm">
                <button
                    type="submit"
                    :disabled="form.processing || form.key === ''"
                    class="rounded-xl bg-ink-100 px-4 py-2 font-semibold text-ink-950 transition hover:bg-white disabled:opacity-50"
                >
                    {{ form.processing ? 'Checking the key…' : 'Save' }}
                </button>
                <button
                    type="button"
                    :disabled="!provider.key.set || checking"
                    class="rounded-xl border border-white/10 px-4 py-2 font-medium text-ink-200 transition hover:bg-white/5 disabled:opacity-40"
                    @click="testKey"
                >
                    {{ checking ? 'Testing…' : 'Test' }}
                </button>
                <button
                    type="button"
                    :disabled="!provider.key.set"
                    class="rounded-xl px-4 py-2 font-medium text-listening transition hover:bg-listening/10 disabled:opacity-40"
                    @click="remove"
                >
                    Remove
                </button>
            </div>
        </form>

        <p v-if="check" role="status" class="mt-3 text-sm" :class="check.ok ? 'text-emerald-300' : 'text-listening'">{{ check.message }}</p>
    </article>
</template>
