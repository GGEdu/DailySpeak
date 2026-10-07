<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from '../components/AppLogo.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const status = computed(() => page.props.flash?.status);
</script>

<template>
    <div class="relative min-h-screen">
        <div
            class="pointer-events-none fixed inset-x-0 top-0 h-[480px] bg-[radial-gradient(60%_60%_at_50%_0%,color-mix(in_oklab,var(--color-thinking)_14%,transparent),transparent)]"
            aria-hidden="true"
        />

        <header class="sticky top-0 z-30 border-b border-white/5 bg-ink-950/75 backdrop-blur-xl">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-5 sm:px-8">
                <Link href="/feed" aria-label="DailySpeak — today's news">
                    <AppLogo />
                </Link>

                <nav class="flex items-center gap-3 text-sm sm:gap-5">
                    <Link href="/feed" class="hidden text-ink-400 transition hover:text-ink-100 sm:inline">Today's news</Link>
                    <Link v-if="user.is_admin" href="/admin/sources" class="text-ink-400 transition hover:text-ink-100">Sources</Link>
                    <span
                        class="rounded-full border border-white/10 px-2.5 py-0.5 text-xs font-medium text-ink-300"
                        :title="`Your English level: ${user.current_level}`"
                    >
                        {{ user.current_level }}
                    </span>
                    <span class="hidden text-ink-300 md:inline">{{ user.name }}</span>
                    <Link href="/logout" method="post" as="button" class="text-ink-400 transition hover:text-ink-100">Log out</Link>
                </nav>
            </div>
        </header>

        <main class="relative mx-auto max-w-6xl px-5 py-10 sm:px-8 sm:py-14">
            <p
                v-if="status"
                role="status"
                class="mx-auto mb-8 max-w-3xl rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200"
            >
                {{ status }}
            </p>

            <slot />
        </main>
    </div>
</template>
