<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLogo from '../components/AppLogo.vue';

const features = [
    {
        title: 'Real news, every morning',
        body: 'Fresh stories from the BBC, summarised for C1 readers with the five words worth stealing.',
    },
    {
        title: 'A sparring partner, not a teacher',
        body: 'Speak your mind out loud. A native-level tutor pushes back, asks why, and makes you defend it.',
    },
    {
        title: 'Fluency under pressure',
        body: 'No grammar nagging mid-sentence. Just the cognitive load of a real argument, in real time.',
    },
];
</script>

<template>
    <Head title="Stop studying English" />

    <div class="relative min-h-screen overflow-hidden">
        <div
            class="pointer-events-none absolute inset-x-0 top-0 h-[640px] bg-[radial-gradient(55%_55%_at_50%_0%,color-mix(in_oklab,var(--color-thinking)_22%,transparent),transparent)]"
            aria-hidden="true"
        />

        <header class="relative mx-auto flex h-20 max-w-6xl items-center justify-between px-5 sm:px-8">
            <AppLogo />
            <nav class="flex items-center gap-2 text-sm">
                <Link href="/login" class="rounded-lg px-3 py-2 text-ink-300 transition hover:text-ink-100">Log in</Link>
                <Link href="/register" class="rounded-lg bg-ink-100 px-3.5 py-2 font-medium text-ink-950 transition hover:bg-white">
                    Get started
                </Link>
            </nav>
        </header>

        <main class="relative mx-auto max-w-6xl px-5 pt-16 pb-24 sm:px-8 sm:pt-24">
            <div class="mx-auto max-w-3xl text-center">
                <p class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.03] px-3 py-1 text-xs font-medium text-ink-300">
                    <span class="size-1.5 rounded-full bg-speaking shadow-[0_0_10px_var(--color-speaking)]" />
                    For B2–C1 English speakers
                </p>

                <h1 class="mt-7 text-4xl font-semibold tracking-tight text-balance text-ink-100 sm:text-6xl sm:leading-[1.05]">
                    Stop studying English.
                    <span class="text-gradient block">Start debating the world.</span>
                </h1>

                <p class="mx-auto mt-6 max-w-xl text-base text-pretty text-ink-400 sm:text-lg">
                    Pick today's headline, take a side and argue it out loud with an AI tutor that never lets you off the hook.
                </p>

                <div class="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <Link
                        href="/register"
                        class="w-full rounded-xl bg-gradient-to-r from-thinking to-speaking px-6 py-3 text-sm font-semibold text-ink-950 shadow-[0_10px_40px_-12px_var(--color-speaking)] transition hover:brightness-110 sm:w-auto"
                    >
                        Start your first debate
                    </Link>
                    <Link href="/login" class="w-full rounded-xl border border-white/10 px-6 py-3 text-sm font-medium text-ink-200 transition hover:bg-white/5 sm:w-auto">
                        I already have an account
                    </Link>
                </div>
            </div>

            <div class="mx-auto mt-24 grid max-w-5xl gap-4 sm:grid-cols-3">
                <div v-for="feature in features" :key="feature.title" class="rounded-2xl border border-white/8 bg-ink-900/60 p-6">
                    <h2 class="text-sm font-semibold text-ink-100">{{ feature.title }}</h2>
                    <p class="mt-2 text-sm leading-relaxed text-ink-400">{{ feature.body }}</p>
                </div>
            </div>
        </main>
    </div>
</template>
