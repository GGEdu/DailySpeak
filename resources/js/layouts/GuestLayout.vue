<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from '../components/AppLogo.vue';

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
});

const status = computed(() => usePage().props.flash?.status);
</script>

<template>
    <div class="relative grid min-h-screen place-items-center px-5 py-16">
        <div
            class="pointer-events-none fixed inset-0 bg-[radial-gradient(50%_45%_at_50%_0%,color-mix(in_oklab,var(--color-thinking)_16%,transparent),transparent)]"
            aria-hidden="true"
        />

        <div class="relative w-full max-w-sm">
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
        </div>
    </div>
</template>
