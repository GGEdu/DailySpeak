<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

defineProps({
    levels: { type: Array, required: true },
});

const form = useForm({
    current_level: usePage().props.auth.user.current_level,
});

const submit = () => form.put('/settings', { preserveScroll: true });
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
    </div>
</template>
