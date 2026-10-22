<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import SectionTabs from '../../components/SectionTabs.vue';
import TextField from '../../components/TextField.vue';
import AppLayout from '../../layouts/AppLayout.vue';
import { timeAgo } from '../../lib/time.js';
import { SETTINGS_TABS } from '../../lib/tabs.js';
import { capLabel, capUsage, formatCount, monthLabel, paidByLabel, resultLabel, taskName, tokensLabel } from '../../lib/usage.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    // "YYYY-MM": the month the figures are for.
    month: { type: String, required: true },
    // Your own monthly limit on AI calls, or null for none.
    cap: { type: Number, default: null },
    // The AI calls of this month, whoever paid for them.
    used: { type: Number, default: 0 },
    // [{ task, calls, paid_by_user, paid_by_platform, input_tokens, output_tokens }] for the month.
    by_task: { type: Array, default: () => [] },
    // The last 20 calls: { task, provider, model, ok, created_at }. No content is stored with them.
    recent: { type: Array, default: () => [] },
});

const share = computed(() => capUsage(props.used, props.cap));

const capForm = useForm({ cap: props.cap === null ? '' : String(props.cap) });

const saveCap = () => capForm
    .transform((data) => ({ cap: data.cap === '' ? null : Number(data.cap) }))
    .put('/settings/usage/cap', { preserveScroll: true });

// "No limit" clears the cap at once: the field goes empty with it.
const removeCap = () => router.put('/settings/usage/cap', { cap: null }, {
    preserveScroll: true,
    onSuccess: () => {
        capForm.clearErrors();
        capForm.cap = '';
    },
});
</script>

<template>
    <Head title="Usage" />

    <div class="mx-auto max-w-3xl">
        <header>
            <h1 class="text-3xl font-semibold tracking-tight text-ink-100">Settings</h1>
            <p class="mt-3 text-ink-400">Your AI calls in {{ monthLabel(month) }}, what each one paid for, and your own limit.</p>
        </header>

        <SectionTabs class="mt-8" :tabs="SETTINGS_TABS" label="Settings sections" />

        <section class="mt-10 rounded-2xl border border-white/8 bg-ink-900/60 p-6" aria-labelledby="month-heading">
            <h2 id="month-heading" class="text-sm font-semibold text-ink-100">This month</h2>
            <p class="mt-3 text-2xl font-semibold text-ink-100">{{ formatCount(used) }} {{ used === 1 ? 'call' : 'calls' }}</p>
            <p class="mt-1 text-sm text-ink-400">Your limit: {{ capLabel(cap) }}</p>

            <div
                v-if="share !== null"
                role="progressbar"
                aria-label="Share of your monthly limit used"
                :aria-valuenow="share"
                aria-valuemin="0"
                aria-valuemax="100"
                class="mt-4 h-2 overflow-hidden rounded-full bg-white/5"
            >
                <div class="h-full rounded-full bg-speaking transition-[width]" :style="{ width: `${share}%` }" />
            </div>
        </section>

        <section class="mt-8 rounded-2xl border border-white/8 bg-ink-900/60 p-6" aria-labelledby="cap-heading">
            <h2 id="cap-heading" class="text-sm font-semibold text-ink-100">Your monthly limit</h2>
            <p class="mt-1 text-sm text-ink-400">
                The most AI calls you make in a month, whoever pays for them. When you reach it, the calls stop until next month.
                Leave it empty, or press “No limit”, to have none.
            </p>

            <form class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-end" novalidate @submit.prevent="saveCap">
                <div class="w-full sm:max-w-xs">
                    <TextField
                        v-model="capForm.cap"
                        label="Calls per month"
                        type="number"
                        inputmode="numeric"
                        min="1"
                        step="1"
                        placeholder="No limit"
                        :error="capForm.errors.cap"
                    />
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="submit"
                        :disabled="capForm.processing"
                        class="rounded-xl bg-ink-100 px-4 py-2.5 text-sm font-semibold text-ink-950 transition hover:bg-white disabled:opacity-50"
                    >
                        Save limit
                    </button>
                    <button
                        type="button"
                        :disabled="cap === null"
                        class="rounded-xl border border-white/10 px-4 py-2.5 text-sm font-medium text-ink-200 transition hover:bg-white/5 disabled:opacity-40"
                        @click="removeCap"
                    >
                        No limit
                    </button>
                </div>
            </form>
        </section>

        <section class="mt-8" aria-labelledby="by-task-heading">
            <h2 id="by-task-heading" class="text-sm font-semibold text-ink-100">By task</h2>

            <div class="mt-3 overflow-x-auto rounded-2xl border border-white/8 bg-ink-900/60">
                <table class="w-full min-w-[32rem] text-left text-sm">
                    <caption class="sr-only">AI calls this month by task and by who paid for them</caption>
                    <thead class="text-xs text-ink-500">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Task</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Calls</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Your key</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Platform</th>
                            <th scope="col" class="px-4 py-3 font-medium">Tokens</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5 text-ink-200">
                        <tr v-for="row in by_task" :key="row.task">
                            <th scope="row" class="px-4 py-3 text-left font-medium text-ink-100">{{ taskName(row.task) }}</th>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatCount(row.calls) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatCount(row.paid_by_user) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatCount(row.paid_by_platform) }}</td>
                            <td class="px-4 py-3 text-xs text-ink-400 tabular-nums">{{ tokensLabel(row.input_tokens, row.output_tokens) }}</td>
                        </tr>
                        <tr v-if="by_task.length === 0">
                            <td colspan="5" class="px-4 py-6 text-center text-ink-400">No AI calls this month yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-ink-500">
                “Your key” is what your own key paid for; “Platform” is what the owner’s connection paid for you.
            </p>
        </section>

        <section class="mt-8" aria-labelledby="recent-heading">
            <h2 id="recent-heading" class="text-sm font-semibold text-ink-100">Last 20 calls</h2>
            <p class="mt-1 text-xs text-ink-500">Only when each call happened, what it was for and whether it worked. Nothing you wrote or heard.</p>

            <ul class="mt-3 divide-y divide-white/5 rounded-2xl border border-white/8 bg-ink-900/60">
                <li v-for="(event, index) in recent" :key="index" class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
                    <p class="text-ink-200">
                        <span class="font-medium text-ink-100">{{ taskName(event.task) }}</span>
                        <span class="text-ink-400"> · {{ event.provider ?? 'Platform' }} · {{ event.model ?? 'default model' }}</span>
                    </p>
                    <p class="flex items-center gap-3 text-xs">
                        <span :class="event.ok ? 'text-emerald-300' : 'text-listening'">{{ resultLabel(event.ok) }}</span>
                        <span v-if="event.paid_by" class="text-ink-500">{{ paidByLabel(event.paid_by) }}</span>
                        <time :datetime="event.created_at" class="text-ink-500">{{ timeAgo(event.created_at) }}</time>
                    </p>
                </li>
                <li v-if="recent.length === 0" class="px-5 py-6 text-center text-sm text-ink-400">Nothing yet.</li>
            </ul>
        </section>
    </div>
</template>
