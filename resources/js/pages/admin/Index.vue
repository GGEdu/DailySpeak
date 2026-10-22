<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import SectionTabs from '../../components/SectionTabs.vue';
import AppLayout from '../../layouts/AppLayout.vue';
import { PLATFORM_TABS } from '../../lib/tabs.js';
import { timeAgo } from '../../lib/time.js';
import { formatCount } from '../../lib/usage.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    // One entry per area, from App\Http\Controllers\Admin\OverviewController. `users` is the card of the users page.
    cards: { type: Object, required: true },
});

const ai = computed(() => props.cards.ai ?? { configured: false, host: null });
const budget = computed(() => props.cards.budget ?? { used: 0, limit: 0 });
const sources = computed(() => props.cards.sources ?? { total: 0, warnings: 0, last_fetched_at: null });
const registrationOpen = computed(() => props.cards.registration?.open === true);
const privacy = computed(() => props.cards.privacy ?? { configured: false });
const users = computed(() => props.cards.users ?? null);

// Sign-up is the one switch on the overview. The server answers with a status line.
const toggleRegistration = () => router.put('/admin/registration', { open: !registrationOpen.value }, { preserveScroll: true });
</script>

<template>
    <Head title="Platform" />

    <div class="mx-auto max-w-3xl">
        <header>
            <p class="text-sm font-medium text-ink-400">Platform</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink-100">Overview</h1>
            <p class="mt-3 text-ink-400">What runs on this server, at a glance. Everything here is shared by all users.</p>
        </header>

        <SectionTabs class="mt-8" :tabs="PLATFORM_TABS" label="Platform sections" />

        <div class="grid gap-4 sm:grid-cols-2">
            <section class="rounded-2xl border border-white/8 bg-ink-900/60 p-6" aria-labelledby="card-ai">
                <h2 id="card-ai" class="text-sm font-semibold text-ink-100">AI connection</h2>
                <p class="mt-3 text-sm text-ink-300">
                    {{ ai.configured ? `Connected to ${ai.host ?? 'the configured address'}.` : 'Not configured yet.' }}
                </p>
                <Link href="/admin/ai" class="mt-4 inline-flex min-h-6 items-center text-sm font-medium text-ink-200 underline-offset-4 hover:underline">Open AI connection</Link>
            </section>

            <section class="rounded-2xl border border-white/8 bg-ink-900/60 p-6" aria-labelledby="card-budget">
                <h2 id="card-budget" class="text-sm font-semibold text-ink-100">Shared AI budget</h2>
                <p class="mt-3 text-sm text-ink-300">
                    Used today: {{ formatCount(budget.used) }} of {{ formatCount(budget.limit) }} calls paid by the platform.
                </p>
                <Link href="/admin/limits" class="mt-4 inline-flex min-h-6 items-center text-sm font-medium text-ink-200 underline-offset-4 hover:underline">Open limits</Link>
            </section>

            <section class="rounded-2xl border border-white/8 bg-ink-900/60 p-6" aria-labelledby="card-sources">
                <h2 id="card-sources" class="text-sm font-semibold text-ink-100">News sources</h2>
                <p class="mt-3 text-sm text-ink-300">
                    {{ formatCount(sources.total) }} {{ sources.total === 1 ? 'feed' : 'feeds' }}
                    <span v-if="sources.warnings > 0" class="text-amber-200">· {{ sources.warnings }} need a look</span>
                </p>
                <p class="mt-1 text-xs text-ink-500">
                    {{ sources.last_fetched_at ? `Last read ${timeAgo(sources.last_fetched_at)}` : 'Never read yet' }}
                </p>
                <Link href="/admin/sources" class="mt-4 inline-flex min-h-6 items-center text-sm font-medium text-ink-200 underline-offset-4 hover:underline">Open sources</Link>
            </section>

            <section class="rounded-2xl border border-white/8 bg-ink-900/60 p-6" aria-labelledby="card-registration">
                <h2 id="card-registration" class="text-sm font-semibold text-ink-100">Sign-up</h2>
                <p class="mt-3 text-sm text-ink-300">{{ registrationOpen ? 'Open: anyone can create an account.' : 'Closed: nobody can create an account.' }}</p>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="String(registrationOpen)"
                    class="mt-4 inline-flex min-h-6 items-center gap-3 rounded-lg text-sm font-medium text-ink-200 outline-none focus-visible:ring-2 focus-visible:ring-speaking/40"
                    @click="toggleRegistration"
                >
                    <span
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border transition"
                        :class="registrationOpen ? 'border-speaking/60 bg-speaking/40' : 'border-white/10 bg-ink-950/60'"
                        aria-hidden="true"
                    >
                        <span class="inline-block h-4 w-4 transform rounded-full bg-ink-100 transition" :class="registrationOpen ? 'translate-x-6' : 'translate-x-1'" />
                    </span>
                    Sign-up is {{ registrationOpen ? 'open' : 'closed' }}
                </button>
            </section>

            <section class="rounded-2xl border border-white/8 bg-ink-900/60 p-6" aria-labelledby="card-privacy">
                <h2 id="card-privacy" class="text-sm font-semibold text-ink-100">Privacy notice</h2>
                <p class="mt-3 text-sm text-ink-300">
                    {{ privacy.configured ? 'The person responsible and the contact are set.' : 'The person responsible and the contact are not set yet.' }}
                </p>
                <Link href="/admin/privacy" class="mt-4 inline-flex min-h-6 items-center text-sm font-medium text-ink-200 underline-offset-4 hover:underline">Open privacy notice settings</Link>
            </section>

            <section v-if="users" class="rounded-2xl border border-white/8 bg-ink-900/60 p-6" aria-labelledby="card-users">
                <h2 id="card-users" class="text-sm font-semibold text-ink-100">Users</h2>
                <p class="mt-3 text-sm text-ink-300">
                    {{ formatCount(users.total) }} {{ users.total === 1 ? 'account' : 'accounts' }},
                    {{ formatCount(users.with_key) }} with their own AI key.
                </p>
                <Link href="/admin/users" class="mt-4 inline-flex min-h-6 items-center text-sm font-medium text-ink-200 underline-offset-4 hover:underline">Open users</Link>
            </section>
        </div>
    </div>
</template>
