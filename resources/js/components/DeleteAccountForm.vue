<script setup>
import { useForm } from '@inertiajs/vue3';
import TextField from './TextField.vue';

// The password is the confirmation: erasing the account cannot be undone.
const deletion = useForm({ password: '' });

const deleteAccount = () =>
    deletion.delete('/settings/account', { onFinish: () => deletion.reset('password') });
</script>

<template>
    <form class="mt-5 max-w-sm" @submit.prevent="deleteAccount">
        <TextField
            v-model="deletion.password"
            label="Password"
            type="password"
            autocomplete="current-password"
            required
            :error="deletion.errors.password"
        />
        <div class="mt-5">
            <button
                type="submit"
                :disabled="deletion.processing"
                class="rounded-xl border border-listening/50 px-5 py-2.5 text-sm font-semibold text-listening transition hover:bg-listening/10 disabled:opacity-50"
            >
                Delete my account
            </button>
        </div>
    </form>
</template>
