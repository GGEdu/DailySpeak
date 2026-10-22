<script setup>
import { Head, Link } from '@inertiajs/vue3';
import GuestLayout from '../../layouts/GuestLayout.vue';
</script>

<template>
    <Head title="Sign-up is closed" />

    <GuestLayout title="Sign-up is closed" subtitle="New accounts are not being opened right now.">
        <p class="text-sm leading-relaxed text-ink-300">
            If you already have an account, log in. Otherwise, ask the person who runs DailySpeak to open an account for you.
        </p>

        <Link
            href="/login"
            class="mt-6 block w-full rounded-lg bg-ink-100 px-4 py-2.5 text-center text-sm font-semibold text-ink-950 transition hover:bg-white"
        >
            Log in
        </Link>
    </GuestLayout>
</template>
