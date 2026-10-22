<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '../../layouts/GuestLayout.vue';

defineProps({
    version: { type: String, required: true },
});

const form = useForm({ consent: false });

const submit = () => form.post('/consent');
</script>

<template>
    <Head title="Before you continue" />

    <GuestLayout title="Before you continue" subtitle="The tutor needs your permission to send your voice and your texts to AI providers.">
        <form class="space-y-5" @submit.prevent="submit">
            <label class="flex items-start gap-3 text-sm leading-relaxed text-ink-300">
                <input v-model="form.consent" type="checkbox" required class="mt-1 size-4 shrink-0 accent-speaking" />
                <span>
                    I agree that my voice and my texts are sent to AI service providers so that the tutor can work.
                    <a href="/privacy" target="_blank" rel="noopener" class="font-medium text-ink-100 underline-offset-4 hover:underline">
                        Read the privacy notice (opens in a new tab)
                    </a>
                </span>
            </label>
            <p v-if="form.errors.consent" role="alert" class="text-xs text-listening">{{ form.errors.consent }}</p>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-lg bg-gradient-to-r from-thinking to-speaking px-4 py-2.5 text-sm font-semibold text-ink-950 transition hover:brightness-110 disabled:opacity-60"
            >
                Accept and continue
            </button>
        </form>

        <template #footer>
            <p>
                Not ready yet? You can still download your data or delete your account in
                <Link href="/settings" class="font-medium text-ink-200 underline-offset-4 hover:underline">Settings</Link>.
            </p>
            <p class="mt-3">
                <Link href="/logout" method="post" as="button" class="font-medium text-ink-200 underline-offset-4 hover:underline">Log out</Link>
            </p>
            <p class="mt-4 text-xs text-ink-500">Privacy notice, version {{ version }}</p>
        </template>
    </GuestLayout>
</template>
