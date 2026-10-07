<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import TextField from '../../components/TextField.vue';
import GuestLayout from '../../layouts/GuestLayout.vue';

const form = useForm({
    email: '',
});

const submit = () => form.post('/forgot-password');
</script>

<template>
    <Head title="Forgot your password?" />

    <GuestLayout title="Forgot your password?" subtitle="Enter your email and we'll send you a link to choose a new one.">
        <form class="space-y-5" @submit.prevent="submit">
            <TextField v-model="form.email" label="Email" type="email" autocomplete="email" required autofocus :error="form.errors.email" />

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-lg bg-ink-100 px-4 py-2.5 text-sm font-semibold text-ink-950 transition hover:bg-white disabled:opacity-60"
            >
                Email me a reset link
            </button>
        </form>

        <template #footer>
            Remembered it?
            <Link href="/login" class="font-medium text-ink-200 underline-offset-4 hover:underline">Log in</Link>
        </template>
    </GuestLayout>
</template>
