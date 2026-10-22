<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    // One row per limit, from App\Services\Limits::overview().
    limits: { type: Array, required: true },
    // Today's calls from the shared AI budget, and when the count starts again.
    aiBudget: { type: Object, required: true },
    // One switch per English level, from App\Services\Guided\GuidedSettings::overview().
    guidedDebate: { type: Array, required: true },
});

// One form per limit, so a refused value is reported on its own row. The input holds text, as typed.
const forms = Object.fromEntries(props.limits.map((limit) => [limit.key, useForm({ value: String(limit.value) })]));

const save = (limit) => forms[limit.key].put(`/admin/limits/${limit.key}`, { preserveScroll: true });

// The guided debate flow of new debates at one level. Debates already started keep the flow they began with.
const setGuided = (level) => router.put(`/admin/guided-debate/${level.level}`, { enabled: !level.enabled }, { preserveScroll: true });

const resetToDefault = (limit) => router.post(`/admin/limits/${limit.key}/reset`, {}, {
    preserveScroll: true,
    onSuccess: () => {
        forms[limit.key].clearErrors();
        forms[limit.key].value = String(limit.default);
    },
});
</script>

<template>
    <Head title="Limits" />

    <div class="mx-auto max-w-3xl">
        <header>
            <p class="text-sm font-medium text-ink-400">Admin</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink-100">Limits</h1>
            <p class="mt-3 text-ink-400">
                How much each user, and all users together, may use the app. A change applies from the next request, with no restart.
                A limit nobody has changed uses its default from the server's configuration.
            </p>
        </header>

        <section class="mt-10 rounded-2xl border border-white/8 bg-ink-900/60 p-6" aria-labelledby="ai-budget-title">
            <h2 id="ai-budget-title" class="text-sm font-semibold text-ink-100">Shared AI budget</h2>
            <p class="mt-3 text-2xl font-semibold text-ink-100">used today: {{ aiBudget.used }} of {{ aiBudget.limit }}</p>
            <p class="mt-2 text-sm text-ink-400">The count starts again at {{ aiBudget.resets_at }}. Admins are not counted.</p>
        </section>

        <section class="mt-10 rounded-2xl border border-white/8 bg-ink-900/60 p-6" aria-labelledby="guided-debate-title">
            <h2 id="guided-debate-title" class="text-sm font-semibold text-ink-100">Guided debate</h2>
            <p class="mt-3 text-sm text-ink-400">
                The guided flow opens with a brief of the article and the debate question, and reviews the learner's turns in writing.
                A change applies to debates started from now on; a debate under way keeps the flow it began with.
            </p>

            <ul class="mt-4 divide-y divide-white/8">
                <li v-for="level in guidedDebate" :key="level.level" class="flex items-center justify-between gap-4 py-4">
                    <div class="min-w-0">
                        <p class="font-semibold text-ink-100">{{ level.level }}</p>
                        <p class="mt-1 text-xs text-ink-400">
                            {{ level.enabled ? 'Guided' : 'Classic' }} · default {{ level.default ? 'guided' : 'classic' }} · {{ level.customised ? 'changed by an admin' : 'using the default' }}
                        </p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="String(level.enabled)"
                        :aria-label="`Guided debate at ${level.level}`"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border transition focus:outline-none focus-visible:ring-2 focus-visible:ring-speaking/40"
                        :class="level.enabled ? 'border-speaking/60 bg-speaking/40' : 'border-white/10 bg-ink-950/60'"
                        @click="setGuided(level)"
                    >
                        <span
                            class="inline-block h-4 w-4 transform rounded-full bg-ink-100 transition"
                            :class="level.enabled ? 'translate-x-6' : 'translate-x-1'"
                        />
                    </button>
                </li>
            </ul>
        </section>

        <ul class="mt-8 space-y-3">
            <li v-for="limit in limits" :key="limit.key" class="rounded-2xl border border-white/8 bg-ink-900/60 p-5">
                <form class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between" novalidate @submit.prevent="save(limit)">
                    <div class="min-w-0">
                        <label :for="`limit-${limit.key}`" class="block font-semibold text-ink-100">{{ limit.label }}</label>
                        <p class="mt-1 text-xs text-ink-400">
                            Now {{ limit.value }} · default {{ limit.default }} · {{ limit.customised ? 'changed by an admin' : 'using the default' }}
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-start gap-2 text-sm">
                        <div>
                            <input
                                :id="`limit-${limit.key}`"
                                v-model="forms[limit.key].value"
                                type="number"
                                inputmode="numeric"
                                min="1"
                                :max="limit.max"
                                step="1"
                                required
                                class="w-28 rounded-lg border bg-ink-950/60 px-3 py-2 text-ink-100 transition outline-none focus:ring-2"
                                :class="forms[limit.key].errors.value ? 'border-listening/60 focus:ring-listening/30' : 'border-white/10 focus:border-speaking/60 focus:ring-speaking/20'"
                                :aria-invalid="Boolean(forms[limit.key].errors.value)"
                                :aria-describedby="forms[limit.key].errors.value ? `limit-${limit.key}-error` : undefined"
                            />
                            <p v-if="forms[limit.key].errors.value" :id="`limit-${limit.key}-error`" class="mt-1.5 max-w-48 text-xs text-listening">
                                {{ forms[limit.key].errors.value }}
                            </p>
                        </div>
                        <button
                            type="submit"
                            :disabled="forms[limit.key].processing"
                            class="rounded-xl bg-ink-100 px-3 py-2 font-semibold text-ink-950 transition hover:bg-white disabled:opacity-60"
                        >
                            Save
                        </button>
                        <button
                            type="button"
                            :disabled="!limit.customised"
                            class="rounded-xl border border-white/10 px-3 py-2 text-ink-200 transition hover:bg-white/5 disabled:opacity-40"
                            @click="resetToDefault(limit)"
                        >
                            Back to default
                        </button>
                    </div>
                </form>
            </li>
        </ul>
    </div>
</template>
