<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import SectionTabs from '../../components/SectionTabs.vue';
import TextField from '../../components/TextField.vue';
import AppLayout from '../../layouts/AppLayout.vue';
import { PLATFORM_TABS } from '../../lib/tabs.js';

defineOptions({ layout: AppLayout });

// Each line is { value, default }: the saved one, and the one in .env that a blank field goes back to.
const props = defineProps({
    controller: { type: Object, default: () => ({ value: null, default: null }) },
    contact_email: { type: Object, default: () => ({ value: null, default: null }) },
});

const form = useForm({
    controller: props.controller.value ?? '',
    contact_email: props.contact_email.value ?? '',
});

// A blank line goes back to its default in .env.
const save = () => form
    .transform((data) => ({ controller: data.controller || null, contact_email: data.contact_email || null }))
    .put('/admin/privacy', { preserveScroll: true });

const fallback = (line) => (line.default ? `Blank uses “${line.default}” from .env.` : 'Blank leaves it unset.');
</script>

<template>
    <Head title="Privacy notice" />

    <div class="mx-auto max-w-3xl">
        <header>
            <p class="text-sm font-medium text-ink-400">Platform</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink-100">Privacy notice</h1>
            <p class="mt-3 text-ink-400">
                Who is responsible for the data and where requests go. The notice itself is at
                <Link href="/privacy" class="font-medium text-ink-200 underline-offset-4 hover:underline">/privacy</Link>.
            </p>
        </header>

        <SectionTabs class="mt-8" :tabs="PLATFORM_TABS" label="Platform sections" />

        <form class="mt-10 space-y-6 rounded-2xl border border-white/8 bg-ink-900/60 p-6" novalidate @submit.prevent="save">
            <div>
                <TextField
                    v-model="form.controller"
                    label="Responsible for the data"
                    placeholder="The name of the person or company"
                    :hint="fallback(controller)"
                    :error="form.errors.controller"
                />
            </div>

            <div>
                <TextField
                    v-model="form.contact_email"
                    label="Contact for privacy requests"
                    type="email"
                    autocomplete="email"
                    :hint="fallback(contact_email)"
                    :error="form.errors.contact_email"
                />
            </div>

            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-xl bg-ink-100 px-5 py-2.5 text-sm font-semibold text-ink-950 transition hover:bg-white disabled:opacity-50"
                >
                    Save
                </button>
            </div>
        </form>
    </div>
</template>
