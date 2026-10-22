<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import SectionTabs from '../../components/SectionTabs.vue';
import AppLayout from '../../layouts/AppLayout.vue';
import { PLATFORM_TABS } from '../../lib/tabs.js';
import { timeAgo } from '../../lib/time.js';
import { formatCount } from '../../lib/usage.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    // [{ id, name, email, is_admin, platform_ai, has_key, month_calls, month_errors, created_at }]: no conversations, no keys.
    users: { type: Array, default: () => [] },
});

const me = usePage().props.auth?.user?.id ?? null;
const isMe = (user) => user.id === me;

// An admin always may use the platform's connection, so their switch is fixed on.
const setPlatformAi = (user) => router.put(`/admin/users/${user.id}`, { platform_ai: !user.platform_ai }, { preserveScroll: true });

const setAdmin = (user) => router.put(`/admin/users/${user.id}`, { is_admin: !user.is_admin }, { preserveScroll: true });

// The server refuses to delete or demote the signed-in admin; the buttons say so too.
const remove = (user) => {
    if (confirm(`Delete ${user.name} (${user.email})? Their debates, transcripts, reports, words and recordings are erased. This cannot be undone.`)) {
        router.delete(`/admin/users/${user.id}`, { preserveScroll: true });
    }
};

const joined = (user) => (user.created_at ? timeAgo(user.created_at) : '—');
</script>

<template>
    <Head title="Users" />

    <div class="mx-auto max-w-3xl">
        <header>
            <p class="text-sm font-medium text-ink-400">Platform</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink-100">Users</h1>
            <p class="mt-3 text-ink-400">
                Accounts and their totals. You see the settings and counts of each user, never their conversations, words or keys.
            </p>
        </header>

        <SectionTabs class="mt-8" :tabs="PLATFORM_TABS" label="Platform sections" />

        <p v-if="users.length === 0" class="rounded-2xl border border-dashed border-white/10 p-8 text-center text-sm text-ink-400">No users yet.</p>

        <ul v-else class="divide-y divide-white/5 rounded-2xl border border-white/8 bg-ink-900/60">
            <li v-for="user in users" :key="user.id" class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2 font-semibold text-ink-100">
                        <span class="truncate">{{ user.name }}</span>
                        <span v-if="isMe(user)" class="text-xs font-normal text-ink-400">(you)</span>
                        <span v-if="user.is_admin" class="rounded-full bg-speaking/10 px-2 py-0.5 text-[11px] font-medium text-speaking">Admin</span>
                    </p>
                    <p class="mt-0.5 truncate text-sm text-ink-400">{{ user.email }}</p>
                    <p class="mt-2 text-xs text-ink-500">
                        {{ user.has_key ? 'Has an AI key' : 'No AI key' }} ·
                        {{ formatCount(user.month_calls) }} calls this month ·
                        {{ formatCount(user.month_errors) }} errors ·
                        joined {{ joined(user) }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                    <span v-if="user.is_admin" class="text-xs text-ink-400">Platform AI: always</span>
                    <button
                        v-else
                        type="button"
                        role="switch"
                        :aria-checked="String(user.platform_ai)"
                        :aria-label="`Allow platform AI: ${user.name}`"
                        class="inline-flex min-h-6 items-center gap-2 rounded-lg text-ink-200 outline-none focus-visible:ring-2 focus-visible:ring-speaking/40"
                        @click="setPlatformAi(user)"
                    >
                        <span
                            class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full border transition"
                            :class="user.platform_ai ? 'border-speaking/60 bg-speaking/40' : 'border-white/10 bg-ink-950/60'"
                            aria-hidden="true"
                        >
                            <span class="inline-block h-3 w-3 transform rounded-full bg-ink-100 transition" :class="user.platform_ai ? 'translate-x-5' : 'translate-x-1'" />
                        </span>
                        Allow platform AI
                    </button>

                    <button
                        type="button"
                        :disabled="isMe(user)"
                        :title="isMe(user) ? 'You cannot change your own admin role.' : undefined"
                        :aria-label="`${user.is_admin ? 'Remove admin' : 'Make admin'}: ${user.name}`"
                        class="rounded-lg border border-white/10 px-3 py-1.5 text-ink-200 transition hover:bg-white/5 disabled:opacity-40"
                        @click="setAdmin(user)"
                    >
                        {{ user.is_admin ? 'Remove admin' : 'Make admin' }}
                    </button>

                    <button
                        type="button"
                        :disabled="isMe(user)"
                        :title="isMe(user) ? 'You cannot delete your own account here.' : undefined"
                        :aria-label="`Delete: ${user.name}`"
                        class="rounded-lg px-3 py-1.5 text-listening transition hover:bg-listening/10 disabled:opacity-40"
                        @click="remove(user)"
                    >
                        Delete
                    </button>
                </div>
            </li>
        </ul>
    </div>
</template>
