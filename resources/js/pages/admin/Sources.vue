<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import TextField from '../../components/TextField.vue';
import AppLayout from '../../layouts/AppLayout.vue';
import { timeAgo } from '../../lib/time';

defineOptions({ layout: AppLayout });

defineProps({
    sources: { type: Array, required: true },
});

const form = useForm({
    name: '',
    feed_url: '',
});

const add = () => form.post('/admin/sources', { preserveScroll: true, onSuccess: () => form.reset() });

const toggle = (source) => router.patch(`/admin/sources/${source.id}`, { is_active: !source.is_active }, { preserveScroll: true });

const fetchNow = (source) => router.post(`/admin/sources/${source.id}/fetch`, {}, { preserveScroll: true });

const remove = (source) => {
    if (confirm(`Delete “${source.name}”? Its articles stay in the feed.`)) {
        router.delete(`/admin/sources/${source.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="News sources" />

    <div class="mx-auto max-w-3xl">
        <header>
            <p class="text-sm font-medium text-ink-400">Admin</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink-100">News sources</h1>
            <p class="mt-3 text-ink-400">RSS feeds read every day by the harvester. Check each publisher's terms before using its content commercially.</p>
        </header>

        <form class="mt-10 rounded-2xl border border-white/8 bg-ink-900/60 p-6" @submit.prevent="add">
            <h2 class="text-sm font-semibold text-ink-100">Add a source</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                <TextField v-model="form.name" label="Name" placeholder="The Guardian – World" required :error="form.errors.name" />
                <TextField v-model="form.feed_url" label="RSS feed URL" type="url" placeholder="https://…/rss.xml" required :error="form.errors.feed_url" />
            </div>
            <div class="mt-5 flex items-center justify-between gap-4">
                <p class="text-xs text-ink-500">The feed is downloaded and checked before it is saved.</p>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="shrink-0 rounded-xl bg-ink-100 px-4 py-2.5 text-sm font-semibold text-ink-950 transition hover:bg-white disabled:opacity-60"
                >
                    {{ form.processing ? 'Checking feed…' : 'Add source' }}
                </button>
            </div>
        </form>

        <ul class="mt-8 space-y-3">
            <li v-if="sources.length === 0" class="rounded-2xl border border-dashed border-white/10 p-8 text-center text-sm text-ink-400">
                No sources yet. Add the first RSS feed above.
            </li>

            <li v-for="source in sources" :key="source.id" class="rounded-2xl border border-white/8 bg-ink-900/60 p-5">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h3 class="font-semibold text-ink-100">{{ source.name }}</h3>
                            <span
                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="source.is_active ? 'bg-emerald-400/10 text-emerald-300' : 'bg-white/5 text-ink-400'"
                            >
                                {{ source.is_active ? 'Active' : 'Paused' }}
                            </span>
                        </div>
                        <p class="mt-1 truncate text-sm text-ink-400" :title="source.feed_url">{{ source.feed_url }}</p>
                        <p class="mt-2 text-xs text-ink-500">
                            {{ source.articles_count }} {{ source.articles_count === 1 ? 'article' : 'articles' }} ·
                            {{ source.last_fetched_at ? `last read ${timeAgo(source.last_fetched_at)}` : 'never read yet' }}
                        </p>
                        <p v-if="source.last_error" class="mt-2 text-xs text-listening">Last run failed: {{ source.last_error }}</p>
                    </div>

                    <div class="flex shrink-0 gap-2 text-sm">
                        <button type="button" class="rounded-lg border border-white/10 px-3 py-1.5 text-ink-200 transition hover:bg-white/5" @click="fetchNow(source)">
                            Fetch now
                        </button>
                        <button type="button" class="rounded-lg border border-white/10 px-3 py-1.5 text-ink-200 transition hover:bg-white/5" @click="toggle(source)">
                            {{ source.is_active ? 'Pause' : 'Activate' }}
                        </button>
                        <button type="button" class="rounded-lg px-3 py-1.5 text-listening transition hover:bg-listening/10" @click="remove(source)">Delete</button>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>
