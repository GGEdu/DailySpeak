<script setup>
defineProps({
    brief: { type: Object, required: true },
});
</script>

<template>
    <div class="space-y-5 text-left">
        <h2 data-selectable class="text-lg font-semibold tracking-tight text-balance text-ink-100">{{ brief.headline }}</h2>

        <section>
            <h3 class="text-xs font-semibold tracking-wide text-ink-400 uppercase">What happened</h3>
            <p data-selectable class="mt-1.5 text-[15px] leading-relaxed text-ink-300">{{ brief.what_happened }}</p>
        </section>

        <section>
            <h3 class="text-xs font-semibold tracking-wide text-ink-400 uppercase">Why it matters</h3>
            <p data-selectable class="mt-1.5 text-[15px] leading-relaxed text-ink-300">{{ brief.why_it_matters }}</p>
        </section>

        <section v-if="brief.background">
            <h3 class="text-xs font-semibold tracking-wide text-ink-400 uppercase">Background</h3>
            <p data-selectable class="mt-1.5 text-[15px] leading-relaxed text-ink-300">{{ brief.background }}</p>
        </section>

        <section>
            <h3 class="text-xs font-semibold tracking-wide text-ink-400 uppercase">Two sides</h3>
            <!-- One column per side; they stack on phones. -->
            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                <div class="rounded-2xl border border-white/8 bg-ink-900/60 p-4">
                    <h4 class="text-sm font-semibold text-speaking">For</h4>
                    <ul class="mt-2 space-y-2 text-sm leading-relaxed text-ink-300">
                        <li v-for="(point, index) in brief.sides.for" :key="index" data-selectable>{{ point }}</li>
                    </ul>
                </div>
                <div class="rounded-2xl border border-white/8 bg-ink-900/60 p-4">
                    <h4 class="text-sm font-semibold text-listening">Against</h4>
                    <ul class="mt-2 space-y-2 text-sm leading-relaxed text-ink-300">
                        <li v-for="(point, index) in brief.sides.against" :key="index" data-selectable>{{ point }}</li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-speaking/25 bg-speaking/[0.06] p-4">
            <h3 class="text-xs font-semibold tracking-wide text-speaking uppercase">The debate question</h3>
            <p data-selectable class="mt-1.5 text-base leading-relaxed font-medium text-ink-100">{{ brief.question }}</p>
        </section>

        <section v-if="brief.key_words.length">
            <h3 class="text-xs font-semibold tracking-wide text-ink-400 uppercase">Key words</h3>
            <ul class="mt-2 flex flex-wrap gap-1.5">
                <li
                    v-for="word in brief.key_words"
                    :key="word"
                    data-selectable
                    class="rounded-md border border-white/10 bg-white/5 px-2 py-0.5 text-xs font-medium text-ink-200"
                >
                    {{ word }}
                </li>
            </ul>
        </section>
    </div>
</template>
