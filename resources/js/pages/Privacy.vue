<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogo from '../components/AppLogo.vue';
import '../lib/navigation';

const props = defineProps({
    version: { type: String, required: true },
    retention: {
        type: Object,
        required: true,
        // recordings and replies: days; transcripts: days after the debate ends, 0 = while the account exists.
    },
});

const transcriptsPeriod = computed(() =>
    props.retention.transcripts === 0
        ? 'for as long as your account exists'
        : `${props.retention.transcripts} days after the debate ends`,
);

const signedIn = computed(() => Boolean(usePage().props.auth?.user));
</script>

<template>
    <Head title="Privacy notice" />

    <div class="relative min-h-screen">
        <a href="#content" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-lg focus:bg-ink-100 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-ink-950">Skip to content</a>

        <header class="relative mx-auto flex h-20 max-w-3xl items-center justify-between px-5 sm:px-8">
            <Link href="/" aria-label="DailySpeak home"><AppLogo /></Link>
            <nav class="flex items-center gap-2 text-sm">
                <Link v-if="signedIn" href="/feed" class="rounded-lg px-3 py-2 text-ink-300 transition hover:text-ink-100">Feed</Link>
                <Link v-else href="/login" class="rounded-lg px-3 py-2 text-ink-300 transition hover:text-ink-100">Log in</Link>
            </nav>
        </header>

        <main id="content" tabindex="-1" class="relative mx-auto max-w-3xl px-5 pt-10 pb-24 outline-none sm:px-8">
            <h1 class="text-3xl font-semibold tracking-tight text-ink-100">Privacy notice</h1>
            <p class="mt-2 text-sm text-ink-500">Version {{ version }}</p>

            <p class="mt-6 leading-relaxed text-ink-300">
                DailySpeak is a debate tutor. You pick a news story, take a side and argue it out loud with an AI tutor.
                This notice says what we keep, who receives it, for how long, and what you can do about it.
            </p>

            <section class="mt-10" aria-labelledby="keep-heading">
                <h2 id="keep-heading" class="text-lg font-semibold text-ink-100">What we keep</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5 leading-relaxed text-ink-300">
                    <li><span class="text-ink-100">Your account:</span> name, email address, password (stored only as a hash), English level and whether your address is confirmed.</li>
                    <li><span class="text-ink-100">Debates:</span> the news story you chose, the text of your turns and of the tutor's replies (transcripts), and the fluency report at the end.</li>
                    <li><span class="text-ink-100">Vocabulary:</span> the words you save, with their translations and explanations.</li>
                    <li><span class="text-ink-100">Voice recordings:</span> the audio of your turns.</li>
                    <li><span class="text-ink-100">Tutor audio:</span> the spoken replies of the tutor.</li>
                </ul>
            </section>

            <section class="mt-10" aria-labelledby="long-heading">
                <h2 id="long-heading" class="text-lg font-semibold text-ink-100">How long we keep it</h2>
                <ul class="mt-3 list-disc space-y-2 pl-5 leading-relaxed text-ink-300">
                    <li>Voice recordings: deleted {{ retention.recordings }} days after they are made.</li>
                    <li>Tutor audio: deleted {{ retention.replies }} days after it is made.</li>
                    <li>Transcripts and fluency reports: kept {{ transcriptsPeriod }}.</li>
                    <li>Vocabulary: kept for as long as your account exists.</li>
                </ul>
                <p class="mt-3 leading-relaxed text-ink-400">Deleting your account erases all of the above at once.</p>
            </section>

            <section class="mt-10" aria-labelledby="who-heading">
                <h2 id="who-heading" class="text-lg font-semibold text-ink-100">Who receives it</h2>
                <p class="mt-3 leading-relaxed text-ink-300">
                    For the tutor to work, your voice and your texts are sent to AI service providers, through a gateway we run:
                    speech-to-text turns your voice into text, a language model writes the tutor's replies and your fluency report,
                    and text-to-speech speaks the replies. Your address also receives the verification and password-reset emails we send.
                </p>
            </section>

            <section class="mt-10" aria-labelledby="basis-heading">
                <h2 id="basis-heading" class="text-lg font-semibold text-ink-100">Why we can do it</h2>
                <p class="mt-3 leading-relaxed text-ink-300">
                    On your consent (GDPR article 6(1)(a)). You give it when you sign up, or when you accept this notice if your
                    account is older. We record the date and the version of the notice you accepted. You can withdraw it at any time
                    by deleting your account.
                </p>
            </section>

            <section class="mt-10" aria-labelledby="rights-heading">
                <h2 id="rights-heading" class="text-lg font-semibold text-ink-100">Your rights</h2>
                <p class="mt-3 leading-relaxed text-ink-300">
                    In <Link href="/settings" class="font-medium text-ink-100 underline-offset-4 hover:underline">Settings</Link>
                    you can download your data as a JSON file, and delete your account. The download holds your profile, debates,
                    transcripts, reports and vocabulary; audio recordings are not included. Deleting the account cannot be undone.
                    You can do both without accepting this notice.
                </p>
            </section>

            <section class="mt-10" aria-labelledby="check-heading">
                <h2 id="check-heading" class="text-lg font-semibold text-ink-100">The human check</h2>
                <p class="mt-3 leading-relaxed text-ink-300">
                    The check on sign-up and on password reset runs a short calculation in your browser. It uses no cookies,
                    no images and no third-party service.
                </p>
            </section>
        </main>
    </div>
</template>
