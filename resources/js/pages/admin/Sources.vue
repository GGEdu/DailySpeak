<script setup>
import { Head, router, useForm, usePoll } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import TextField from '../../components/TextField.vue';
import AppLayout from '../../layouts/AppLayout.vue';
import { timeAgo } from '../../lib/time';

defineOptions({ layout: AppLayout });

const props = defineProps({
    sources: { type: Array, required: true },
    // [{ key, label }] from config/news.php.
    categories: { type: Array, required: true },
});

// While a usage-terms check is queued or running, the page asks for the sources again, so its row updates by itself.
const hasPendingCheck = computed(() => props.sources.some((source) => source.policy_status === 'checking'));
const { start: pollSources, stop: stopPolling } = usePoll(3000, { only: ['sources'], preserveScroll: true }, { autoStart: false });
const syncPolling = (pending) => (pending ? pollSources() : stopPolling());

onMounted(() => syncPolling(hasPendingCheck.value));
watch(hasPendingCheck, syncPolling);

const form = useForm({
    name: '',
    feed_url: '',
    category: '',
});

const add = () => form.post('/admin/sources', { preserveScroll: true, onSuccess: () => form.reset() });

const toggle = (source) => router.patch(`/admin/sources/${source.id}`, { is_active: !source.is_active }, { preserveScroll: true });

// The select shows the server's category: if the server refuses the change, it goes back to the previous one.
const changeCategory = (source, event) => {
    const select = event.target;
    const previous = source.category ?? '';

    router.patch(`/admin/sources/${source.id}`, { category: select.value }, {
        preserveScroll: true,
        onError: () => {
            select.value = previous;
        },
    });
};

const checkPolicy = (source) => router.patch(`/admin/sources/${source.id}`, { check_policy: true }, { preserveScroll: true });

// Which rows have their usage note open, by source id.
const notesOpen = ref({});

const toggleNote = (source) => {
    notesOpen.value = { ...notesOpen.value, [source.id]: !notesOpen.value[source.id] };
};

const policyBadge = (source) => (source.policy_status === 'warning' ? 'Usage terms' : 'Terms not checked');

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
            <div class="mt-4 grid gap-4 sm:grid-cols-[minmax(0,2fr)_minmax(0,3fr)_minmax(0,1.5fr)]">
                <TextField v-model="form.name" label="Name" placeholder="The Guardian – World" required :error="form.errors.name" />
                <TextField v-model="form.feed_url" label="RSS feed URL" type="url" placeholder="https://…/rss.xml" required :error="form.errors.feed_url" />
                <div>
                    <label for="source-category" class="block text-sm font-medium text-ink-300">Category</label>
                    <select
                        id="source-category"
                        v-model="form.category"
                        required
                        class="mt-1.5 block w-full rounded-lg border bg-ink-950/60 px-3 py-2.5 text-sm text-ink-100 transition outline-none focus:ring-2"
                        :class="form.errors.category ? 'border-listening/60 focus:ring-listening/30' : 'border-white/10 focus:border-speaking/60 focus:ring-speaking/20'"
                        :aria-invalid="Boolean(form.errors.category)"
                    >
                        <option value="" disabled>Choose…</option>
                        <option v-for="category in categories" :key="category.key" :value="category.key">{{ category.label }}</option>
                    </select>
                    <p v-if="form.errors.category" class="mt-1.5 text-xs text-listening">{{ form.errors.category }}</p>
                </div>
            </div>
            <div class="mt-5 flex items-center justify-between gap-4">
                <p class="text-xs text-ink-500">The feed is downloaded and checked before it is saved. Its usage terms are checked in the background.</p>
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
                            <span
                                v-if="source.policy_status === 'checking'"
                                role="status"
                                class="rounded-full bg-speaking/10 px-2 py-0.5 text-[11px] font-medium text-speaking"
                            >
                                Checking terms…
                            </span>
                            <button
                                v-else-if="source.policy_status !== 'ok'"
                                type="button"
                                class="rounded-full px-2 py-0.5 text-[11px] font-medium transition hover:brightness-125"
                                :class="source.policy_status === 'warning' ? 'bg-amber-400/10 text-amber-300' : 'bg-white/5 text-ink-300'"
                                :aria-expanded="notesOpen[source.id] ? 'true' : 'false'"
                                :aria-controls="`policy-note-${source.id}`"
                                :aria-label="`${policyBadge(source)}: ${source.name}`"
                                @click="toggleNote(source)"
                            >
                                {{ policyBadge(source) }}
                            </button>
                            <select
                                :value="source.category ?? ''"
                                :aria-label="`Category of ${source.name}`"
                                class="rounded-md border border-white/10 bg-ink-950/60 px-2 py-0.5 text-xs text-ink-200 outline-none focus:border-speaking/60"
                                @change="changeCategory(source, $event)"
                            >
                                <option value="" disabled>No category</option>
                                <option v-for="category in categories" :key="category.key" :value="category.key">{{ category.label }}</option>
                            </select>
                        </div>
                        <p class="mt-1 truncate text-sm text-ink-400" :title="source.feed_url">{{ source.feed_url }}</p>
                        <p class="mt-2 text-xs text-ink-500">
                            {{ source.articles_count }} {{ source.articles_count === 1 ? 'article' : 'articles' }} ·
                            {{ source.last_fetched_at ? `last read ${timeAgo(source.last_fetched_at)}` : 'never read yet' }}
                        </p>
                        <p v-if="source.last_error" class="mt-2 text-xs text-listening">Last run failed: {{ source.last_error }}</p>
                        <p v-show="notesOpen[source.id]" :id="`policy-note-${source.id}`" class="mt-2 max-w-prose text-xs text-ink-300">
                            {{ source.policy_note ?? 'Not checked yet. Use Re-check to read its robots.txt and publisher terms.' }}
                            <span v-if="source.policy_checked_at" class="text-ink-500">Checked {{ timeAgo(source.policy_checked_at) }}.</span>
                        </p>
                    </div>

                    <div class="flex shrink-0 gap-2 text-sm">
                        <button
                            type="button"
                            class="rounded-lg border border-white/10 px-3 py-1.5 text-ink-200 transition hover:bg-white/5"
                            :aria-label="`Fetch now: ${source.name}`"
                            @click="fetchNow(source)"
                        >
                            Fetch now
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-white/10 px-3 py-1.5 text-ink-200 transition hover:bg-white/5"
                            :aria-label="`Re-check: ${source.name}`"
                            @click="checkPolicy(source)"
                        >
                            Re-check
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-white/10 px-3 py-1.5 text-ink-200 transition hover:bg-white/5"
                            :aria-label="`${source.is_active ? 'Pause' : 'Activate'}: ${source.name}`"
                            @click="toggle(source)"
                        >
                            {{ source.is_active ? 'Pause' : 'Activate' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg px-3 py-1.5 text-listening transition hover:bg-listening/10"
                            :aria-label="`Delete: ${source.name}`"
                            @click="remove(source)"
                        >
                            Delete
                        </button>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>
