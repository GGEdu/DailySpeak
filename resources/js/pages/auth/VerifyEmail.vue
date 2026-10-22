<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import DeleteAccountForm from '../../components/DeleteAccountForm.vue';
import GuestLayout from '../../layouts/GuestLayout.vue';

defineProps({
    email: { type: String, required: true },
});

const status = computed(() => usePage().props.flash?.status);
const form = useForm({});
const resend = () => form.post('/email/verification-notification');
</script>

<template>
    <Head title="Check your inbox" />

    <GuestLayout title="Check your inbox" :subtitle="`We sent a confirmation link to ${email}. Open it to start debating.`">
        <p v-if="status" role="status" class="mb-5 rounded-lg border border-emerald-400/20 bg-emerald-400/10 px-3.5 py-2.5 text-sm text-emerald-200">
            {{ status }}
        </p>

        <button
            type="button"
            :disabled="form.processing"
            class="w-full rounded-lg bg-ink-100 px-4 py-2.5 text-sm font-semibold text-ink-950 transition hover:bg-white disabled:opacity-60"
            @click="resend"
        >
            Send the link again
        </button>
        <p class="mt-4 text-sm text-ink-400">Not there? Check your spam folder. The link expires in 60 minutes.</p>

        <details class="mt-6 border-t border-white/8 pt-5">
            <summary class="cursor-pointer text-sm font-medium text-ink-300 hover:text-ink-100">Wrong address? Delete this account</summary>
            <p class="mt-3 text-sm text-ink-400">
                This erases the account. You can then sign up again with the right address. It cannot be undone.
            </p>
            <DeleteAccountForm />
        </details>

        <template #footer>
            <Link href="/logout" method="post" as="button" class="font-medium text-ink-200 underline-offset-4 hover:underline">Log out</Link>
            or delete this account if the address was wrong.
        </template>
    </GuestLayout>
</template>
