<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import TextField from '../../components/TextField.vue';
import GuestLayout from '../../layouts/GuestLayout.vue';

defineProps({
    levels: { type: Array, required: true },
});

const form = useForm({
    name: '',
    email: '',
    current_level: 'B2',
    password: '',
    password_confirmation: '',
});

const submit = () => form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <Head title="Create your account" />

    <GuestLayout title="Create your account" subtitle="Two minutes from now you'll be defending your first opinion.">
        <form class="space-y-5" @submit.prevent="submit">
            <TextField v-model="form.name" label="Name" autocomplete="name" required autofocus :error="form.errors.name" />
            <TextField v-model="form.email" label="Email" type="email" autocomplete="email" required :error="form.errors.email" />

            <fieldset>
                <legend class="text-sm font-medium text-ink-300">Your English level</legend>
                <div class="mt-1.5 grid grid-cols-3 gap-2">
                    <label
                        v-for="level in levels"
                        :key="level"
                        class="cursor-pointer rounded-lg border px-3 py-2 text-center text-sm font-medium transition has-focus-visible:ring-2 has-focus-visible:ring-speaking/30"
                        :class="form.current_level === level ? 'border-speaking/60 bg-speaking/10 text-ink-100' : 'border-white/10 text-ink-400 hover:text-ink-200'"
                    >
                        <input v-model="form.current_level" type="radio" name="current_level" :value="level" class="sr-only" />
                        {{ level }}
                    </label>
                </div>
                <p v-if="form.errors.current_level" class="mt-1.5 text-xs text-listening">{{ form.errors.current_level }}</p>
            </fieldset>

            <TextField v-model="form.password" label="Password" type="password" autocomplete="new-password" required :error="form.errors.password" />
            <TextField v-model="form.password_confirmation" label="Confirm password" type="password" autocomplete="new-password" required />

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-lg bg-gradient-to-r from-thinking to-speaking px-4 py-2.5 text-sm font-semibold text-ink-950 transition hover:brightness-110 disabled:opacity-60"
            >
                Create account
            </button>
        </form>

        <template #footer>
            Already debating?
            <Link href="/login" class="font-medium text-ink-200 underline-offset-4 hover:underline">Log in</Link>
        </template>
    </GuestLayout>
</template>
