<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from '../components/AppLogo.vue';
import '../lib/navigation';

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
});

const status = computed(() => usePage().props.flash?.status);
</script>

<template>
    <div class="relative grid min-h-screen place-items-center px-5 py-16">
        <a href="#content" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-lg focus:bg-ink-100 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-ink-950">Skip to content</a>
        <div
            class="pointer-events-none fixed inset-0 bg-[radial-gradient(50%_45%_at_50%_0%,color-mix(in_oklab,var(--color-thinking)_16%,transparent),transparent)]"
            aria-hidden="true"
        />

        <main id="content" tabindex="-1" class="relative w-full max-w-sm outline-none">
            <Link href="/" class="mb-10 flex justify-center">
                <AppLogo />
            </Link>

            <div class="rounded-2xl border border-white/8 bg-ink-900/80 p-7 shadow-2xl shadow-black/40 backdrop-blur">
                <h1 class="text-xl font-semibold tracking-tight text-ink-100">{{ title }}</h1>
                <p v-if="subtitle" class="mt-1.5 text-sm text-ink-400">{{ subtitle }}</p>

                <p
                    v-if="status"
                    role="status"
                    class="mt-5 rounded-lg border border-emerald-400/20 bg-emerald-400/10 px-3 py-2.5 text-sm text-emerald-200"
                >
                    {{ status }}
                </p>

                <div class="mt-7">
                    <slot />
                </div>
            </div>

            <div class="mt-6 text-center text-sm text-ink-400">
                <slot name="footer" />
            </div>

            <p class="mt-8 text-center text-xs text-ink-500">
                <Link href="/privacy" class="hover:text-ink-300 underline-offset-4 hover:underline">Privacy</Link>
            </p>
        </main>
    </div>
</template>
