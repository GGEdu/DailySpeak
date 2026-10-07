<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import TextField from '../../components/TextField.vue';
import GuestLayout from '../../layouts/GuestLayout.vue';

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, required: true },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <Head title="Choose a new password" />

    <GuestLayout title="Choose a new password">
        <form class="space-y-5" @submit.prevent="submit">
            <TextField v-model="form.email" label="Email" type="email" autocomplete="email" required :error="form.errors.email" />
            <TextField
                v-model="form.password"
                label="New password"
                type="password"
                autocomplete="new-password"
                required
                autofocus
                :error="form.errors.password"
            />
            <TextField v-model="form.password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" required />

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-lg bg-ink-100 px-4 py-2.5 text-sm font-semibold text-ink-950 transition hover:bg-white disabled:opacity-60"
            >
                Reset password
            </button>
        </form>

        <template #footer>
            <Link href="/login" class="font-medium text-ink-200 underline-offset-4 hover:underline">Back to log in</Link>
        </template>
    </GuestLayout>
</template>
