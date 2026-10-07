<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import TextField from '../../components/TextField.vue';
import GuestLayout from '../../layouts/GuestLayout.vue';

const form = useForm({
    email: '',
    password: '',
    remember: true,
});

const submit = () => form.post('/login', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Log in" />

    <GuestLayout title="Welcome back" subtitle="Today's headlines are waiting for your opinion.">
        <form class="space-y-5" @submit.prevent="submit">
            <TextField v-model="form.email" label="Email" type="email" autocomplete="email" required autofocus :error="form.errors.email" />
            <TextField v-model="form.password" label="Password" type="password" autocomplete="current-password" required :error="form.errors.password" />

            <label class="flex items-center gap-2 text-sm text-ink-400">
                <input v-model="form.remember" type="checkbox" class="size-4 rounded border-white/20 bg-ink-950 accent-speaking" />
                Remember me
            </label>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-lg bg-ink-100 px-4 py-2.5 text-sm font-semibold text-ink-950 transition hover:bg-white disabled:opacity-60"
            >
                Log in
            </button>
        </form>

        <template #footer>
            New here?
            <Link href="/register" class="font-medium text-ink-200 underline-offset-4 hover:underline">Create an account</Link>
        </template>
    </GuestLayout>
</template>
