<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AiTaskField from '../../components/AiTaskField.vue';
import ProviderKeyCard from '../../components/ProviderKeyCard.vue';
import SectionTabs from '../../components/SectionTabs.vue';
import AppLayout from '../../layouts/AppLayout.vue';
import { TASKS, taskChoices } from '../../lib/aiProviders.js';
import { SETTINGS_TABS } from '../../lib/tabs.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    // [{ id, label, capabilities: ["text"|"stt"|"tts"], key: { set, last4, verified_at } }]
    providers: { type: Array, default: () => [] },
    // { conversation, writing, stt: { provider, model }, tts: { provider, model, voice } }
    tasks: { type: Object, default: () => ({}) },
    // Whether the owner lets this user run tasks on the platform's own connection.
    platform: { type: Object, default: () => ({ allowed: false }) },
    // { [providerId]: { conversation, writing, stt, tts, voice } }: the models each provider suggests.
    suggestions: { type: Object, default: () => ({}) },
});

const choicesFor = (task) => taskChoices(task, props.providers, props.platform?.allowed === true);

const form = useForm({
    tasks: Object.fromEntries(
        TASKS.map((task) => [
            task,
            { provider: props.tasks[task]?.provider ?? null, model: props.tasks[task]?.model ?? null, voice: props.tasks[task]?.voice ?? null },
        ]),
    ),
});

// Empty fields go as null; a voice is only sent for text-to-speech.
const save = () => form
    .transform((data) => ({
        tasks: Object.fromEntries(
            Object.entries(data.tasks).map(([task, choice]) => [
                task,
                {
                    provider: choice.provider || null,
                    model: choice.model || null,
                    ...(task === 'tts' ? { voice: choice.voice || null } : {}),
                },
            ]),
        ),
    }))
    .put('/settings/ai/tasks', { preserveScroll: true });

const errorsFor = (task) => ({
    provider: form.errors[`tasks.${task}.provider`],
    model: form.errors[`tasks.${task}.model`],
    voice: form.errors[`tasks.${task}.voice`],
});
</script>

<template>
    <Head title="AI" />

    <div class="mx-auto max-w-3xl">
        <header>
            <h1 class="text-3xl font-semibold tracking-tight text-ink-100">Settings</h1>
        </header>

        <SectionTabs class="mt-8" :tabs="SETTINGS_TABS" label="Settings sections" />

        <section class="mt-10" aria-labelledby="who-pays-heading">
            <h2 id="who-pays-heading" class="text-lg font-semibold text-ink-100">Who pays for what</h2>
            <ul class="mt-3 space-y-2 text-sm leading-relaxed text-ink-300">
                <li>
                    <span class="font-medium text-ink-200">Your key:</span>
                    the voice turns (speech-to-text, the tutor, text-to-speech), translations, the review of your turns, word
                    analysis and the final report.
                </li>
                <li>
                    <span class="font-medium text-ink-200">The platform:</span>
                    the news summaries and the summary of each debate, shared by everyone. You do not pay for them.
                </li>
                <li>
                    A task never switches to another key on its own: if the key it needs is missing, it stops and tells you.
                    Your usage and your monthly limit are on the Usage tab.
                </li>
            </ul>
        </section>

        <section class="mt-12" aria-labelledby="keys-heading">
            <h2 id="keys-heading" class="text-lg font-semibold text-ink-100">Your keys</h2>
            <p class="mt-1 text-sm text-ink-400">
                Only the providers below can be used. A key is stored encrypted, is checked before it is saved and is never shown again.
            </p>

            <div class="mt-5 grid gap-4 md:grid-cols-2">
                <ProviderKeyCard v-for="provider in providers" :key="provider.id" :provider="provider" />
            </div>
            <p v-if="providers.length === 0" class="mt-5 rounded-2xl border border-dashed border-white/10 p-6 text-sm text-ink-400">
                No providers are available on this server yet.
            </p>
        </section>

        <section class="mt-12" aria-labelledby="tasks-heading">
            <h2 id="tasks-heading" class="text-lg font-semibold text-ink-100">Provider and model for each task</h2>
            <p class="mt-1 text-sm text-ink-400">
                A provider can only be chosen for a task it can do, and only with a key you have saved
                {{ platform?.allowed ? 'or the platform’s connection, which the owner allows you to use' : '' }}.
            </p>

            <form class="mt-5 space-y-4" novalidate @submit.prevent="save">
                <AiTaskField
                    v-for="task in TASKS"
                    :key="task"
                    v-model="form.tasks[task]"
                    :task="task"
                    :choices="choicesFor(task)"
                    :suggestions="suggestions"
                    :error="errorsFor(task)"
                />

                <div class="flex justify-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-xl bg-ink-100 px-5 py-2.5 text-sm font-semibold text-ink-950 transition hover:bg-white disabled:opacity-50"
                    >
                        Save choices
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
