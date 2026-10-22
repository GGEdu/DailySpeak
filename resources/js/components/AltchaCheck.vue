<script setup>
// ALTCHA: a proof-of-work check that runs in the background (no images, no cookies, no third party).
// The widget starts its solver workers from blob: URLs, which the Content Security Policy allows for workers only.
import 'altcha';
import { ref, watch } from 'vue';

const props = defineProps({
    // The server's refusal of this check, shown under the widget.
    error: { type: String, default: null },
});

// The solved payload the form posts. Empty until the widget has verified.
const payload = defineModel({ type: String, default: '' });

const widget = ref(null);

function verified(event) {
    payload.value = event.detail.payload;
}

function stateChanged(event) {
    if (event.detail.state !== 'verified') {
        payload.value = '';
    }
}

// The server refused this solution (used, expired or not for this form): solve a fresh challenge.
watch(
    () => props.error,
    (error) => {
        if (error && widget.value) {
            payload.value = '';
            widget.value.reset();
            widget.value.verify();
        }
    },
);
</script>

<template>
    <div>
        <!-- A custom element: Vue renders it as is, the widget registers it on import. -->
        <component
            :is="'altcha-widget'"
            ref="widget"
            challenge="/altcha/challenge"
            auto="onload"
            @verified="verified"
            @statechange="stateChanged"
        />
        <p v-if="error" role="alert" class="mt-2 text-xs text-listening">{{ error }}</p>
    </div>
</template>
