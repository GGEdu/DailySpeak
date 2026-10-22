<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount } from 'vue';
import AppLogo from '../components/AppLogo.vue';
import SelectionLookup from '../components/SelectionLookup.vue';
import { clearExplanations } from '../lib/lookup';
import '../lib/navigation';

const page = usePage();
const user = computed(() => page.props.auth.user);
const status = computed(() => page.props.flash?.status);
const warning = computed(() => page.props.flash?.warning);
const dueWords = computed(() => page.props.dueWords ?? 0);

// Logging out forgets this visit's explanations: the next person on the device gets none of them.
const stopLogoutListener = router.on('start', (event) => {
    if (String(event.detail.visit.url).endsWith('/logout')) clearExplanations();
});

onBeforeUnmount(stopLogoutListener);
</script>

<template>
    <div class="relative min-h-screen">
        <a href="#content" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-lg focus:bg-ink-100 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-ink-950">Skip to content</a>
        <div
            class="pointer-events-none fixed inset-x-0 top-0 h-[480px] bg-[radial-gradient(60%_60%_at_50%_0%,color-mix(in_oklab,var(--color-thinking)_14%,transparent),transparent)]"
            aria-hidden="true"
        />

        <header class="sticky top-0 z-30 border-b border-white/5 bg-ink-950/75 backdrop-blur-xl">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-5 sm:px-8">
                <Link href="/feed" class="shrink-0" aria-label="DailySpeak — today's news">
                    <AppLogo compact />
                </Link>

                <nav class="flex shrink-0 items-center gap-3 text-sm whitespace-nowrap sm:gap-5">
                    <Link href="/feed" class="hidden min-h-6 items-center text-ink-400 transition hover:text-ink-100 sm:inline-flex">Today's news</Link>
                    <Link href="/vocabulary" class="flex min-h-6 items-center gap-1.5 text-ink-400 transition hover:text-ink-100">
                        Words
                        <span v-if="dueWords" class="rounded-full bg-speaking/15 px-1.5 text-[11px] font-semibold text-speaking" aria-hidden="true">{{ dueWords }}</span>
                        <span v-if="dueWords" class="sr-only">, {{ dueWords }} due for review</span>
                    </Link>
                    <Link v-if="user.is_admin" href="/admin/sources" class="inline-flex min-h-6 items-center text-ink-400 transition hover:text-ink-100">Sources</Link>
                    <Link v-if="user.is_admin" href="/admin/limits" class="inline-flex min-h-6 items-center text-ink-400 transition hover:text-ink-100">Limits</Link>
                    <Link
                        href="/settings"
                        class="inline-flex min-h-6 items-center rounded-full border border-white/10 px-2.5 py-0.5 text-xs font-medium text-ink-300 transition hover:border-white/20 hover:text-ink-100"
                        :title="`Your English level: ${user.current_level}. Change it in your settings.`"
                    >
                        {{ user.current_level }}
                    </Link>
                    <span class="hidden text-ink-300 md:inline">{{ user.name }}</span>
                    <Link href="/logout" method="post" as="button" class="inline-flex min-h-6 items-center text-ink-400 transition hover:text-ink-100">Log out</Link>
                </nav>
            </div>
        </header>

        <main id="content" tabindex="-1" class="relative mx-auto max-w-6xl px-5 py-10 outline-none sm:px-8 sm:py-14">
            <p
                v-if="status"
                role="status"
                class="mx-auto mb-8 max-w-3xl rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200"
            >
                {{ status }}
            </p>
            <!-- A heads-up is not a success: amber, and read out as a status update. -->
            <p
                v-if="warning"
                role="status"
                class="mx-auto mb-8 max-w-3xl rounded-xl border border-amber-300/40 bg-amber-400/10 px-4 py-3 text-sm text-amber-100"
            >
                {{ warning }}
            </p>

            <slot />
        </main>

        <!-- Select a word in any [data-selectable] text to translate it or save it. -->
        <SelectionLookup />
    </div>
</template>
