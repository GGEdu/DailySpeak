<template>
    <span class="inline-flex items-center gap-2.5">
        <span
            class="relative grid size-8 place-items-center rounded-xl bg-gradient-to-br from-thinking to-speaking shadow-[0_0_24px_-6px_var(--color-speaking)]"
            aria-hidden="true"
        >
            <span class="flex h-3.5 items-center gap-[3px]">
                <span v-for="(height, index) in [40, 100, 65, 85]" :key="index" class="w-[3px] rounded-full bg-ink-950" :style="{ height: `${height}%` }" />
            </span>
        </span>
        <span class="text-[15px] font-semibold tracking-tight text-ink-100">DailySpeak</span>
    </span>
</template>
