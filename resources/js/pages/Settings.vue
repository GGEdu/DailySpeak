<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
import TextField from '../components/TextField.vue';

defineOptions({ layout: AppLayout });

defineProps({
    levels: { type: Array, required: true },
});

const form = useForm({
    current_level: usePage().props.auth.user.current_level,
});

const submit = () => form.put('/settings', { preserveScroll: true });

// The password is the confirmation: erasing the account cannot be undone.
const deletion = useForm({ password: '' });

const deleteAccount = () =>
    deletion.delete('/settings/account', { onFinish: () => deletion.reset('password') });
</script>

<template>
    <Head title="Settings" />

    <div class="mx-auto max-w-3xl">
        <header>
            <h1 class="text-3xl font-semibold tracking-tight text-ink-100">Settings</h1>
        </header>

        <form class="mt-10 rounded-3xl border border-white/8 bg-ink-900/60 p-6 sm:p-8" @submit.prevent="submit">
            <fieldset>
                <legend class="font-semibold text-ink-100">Your English level</legend>
                <p class="mt-1 text-sm text-ink-400">The tutor adapts its vocabulary and sentence length to it.</p>

                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                    <label
                        v-for="level in levels"
                        :key="level.value"
                        class="cursor-pointer rounded-2xl border p-4 transition has-focus-visible:ring-2 has-focus-visible:ring-speaking/30"
                        :class="form.current_level === level.value ? 'border-speaking/60 bg-speaking/10' : 'border-white/10 hover:border-white/20'"
                    >
                        <input v-model="form.current_level" type="radio" name="current_level" :value="level.value" class="sr-only" />
                        <span class="block text-sm font-semibold text-ink-100">{{ level.value }} · {{ level.label }}</span>
                        <span class="mt-1.5 block text-sm text-ink-400">{{ level.description }}</span>
                    </label>
                </div>
                <p v-if="form.errors.current_level" class="mt-2 text-xs text-listening">{{ form.errors.current_level }}</p>
            </fieldset>

            <div class="mt-6 flex justify-end">
                <button
                    type="submit"
                    :disabled="form.processing || !form.isDirty"
                    class="rounded-xl bg-ink-100 px-5 py-2.5 text-sm font-semibold text-ink-950 transition hover:bg-white disabled:opacity-50"
                >
                    Save
                </button>
            </div>
        </form>

        <section class="mt-8 rounded-3xl border border-white/8 bg-ink-900/60 p-6 sm:p-8" aria-labelledby="data-heading">
            <h2 id="data-heading" class="font-semibold text-ink-100">Your data</h2>
            <p class="mt-1 text-sm text-ink-400">
                A JSON file with your profile, debates, transcripts, reports and vocabulary. Audio recordings are not included.
            </p>
            <div class="mt-5">
                <a
                    href="/settings/export"
                    class="inline-flex rounded-xl border border-white/10 px-5 py-2.5 text-sm font-semibold text-ink-100 transition hover:border-white/20 focus-visible:ring-2 focus-visible:ring-speaking/30"
                >
                    Download my data
                </a>
            </div>
        </section>

        <section class="mt-8 rounded-3xl border border-listening/30 bg-ink-900/60 p-6 sm:p-8" aria-labelledby="delete-heading">
            <h2 id="delete-heading" class="font-semibold text-listening">Delete account</h2>
            <p class="mt-1 text-sm text-ink-400">
                This erases your account and everything in it: debates, transcripts, reports, vocabulary and voice
                recordings. It cannot be undone. News articles are not affected.
            </p>

            <form class="mt-5 max-w-sm" @submit.prevent="deleteAccount">
                <TextField
                    v-model="deletion.password"
                    label="Password"
                    type="password"
                    autocomplete="current-password"
                    required
                    :error="deletion.errors.password"
                />
                <div class="mt-5">
                    <button
                        type="submit"
                        :disabled="deletion.processing"
                        class="rounded-xl border border-listening/50 px-5 py-2.5 text-sm font-semibold text-listening transition hover:bg-listening/10 disabled:opacity-50"
                    >
                        Delete my account
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
