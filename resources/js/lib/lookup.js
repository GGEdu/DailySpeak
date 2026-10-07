import { ref } from 'vue';
import { postJson } from './http.js';
import { isVocabulary, normalise, sentenceAt } from './text.js';

/**
 * The open lookup panel: { text, context, target, focus } or null. `target` is the live Range of
 * the selection (or the element that was tapped), so the panel can follow it when something scrolls.
 * `focus` tells the panel to take the keyboard focus.
 */
export const activeLookup = ref(null);

const explanations = new Map();

// How the learner last acted. A keyboard user has to reach the panel's buttons with Tab, but a
// mouse or touch user must not have the focus moved under them.
let lastInput = 'pointer';

export function noteInput(kind) {
    lastInput = kind;
}

export function openLookup(text, context, target, { focus = lastInput === 'keyboard' } = {}) {
    activeLookup.value = { text, context, target, focus };
}

export function closeLookup() {
    activeLookup.value = null;
}

/**
 * The current text selection, if it is a word or short expression inside one [data-selectable] block.
 */
export function selectedVocabulary() {
    const selection = document.getSelection();

    if (!selection || selection.isCollapsed || selection.rangeCount === 0) {
        return null;
    }

    const range = selection.getRangeAt(0);
    const block = selectableBlock(range.startContainer);

    if (!block || block !== selectableBlock(range.endContainer)) {
        return null;
    }

    const text = normalise(selection.toString());

    if (!isVocabulary(text)) {
        return null;
    }

    // Offsets of the selection inside the block's text, to find its sentence.
    const before = document.createRange();
    before.selectNodeContents(block);
    before.setEnd(range.startContainer, range.startOffset);
    const start = before.toString().length;

    return { text, context: sentenceAt(block.textContent, start, start + range.toString().length), target: range.cloneRange() };
}

function selectableBlock(node) {
    return (node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement)?.closest('[data-selectable]') ?? null;
}

/**
 * Translate and explain a selection. Answers are kept for the rest of the visit, per user and level
 * (`scope`), since the server pitches the explanation at the user's level.
 */
export async function explain(text, context, scope, signal) {
    const key = `${scope}\n${text.toLowerCase()}\n${context ?? ''}`;

    if (!explanations.has(key)) {
        explanations.set(key, await postJson('/api/lookups', { text, context }, { signal }));
    }

    return explanations.get(key);
}

export function saveWord(word, context) {
    return postJson('/api/vocabulary', { word, context });
}

/**
 * A message the learner can act on, instead of the framework's.
 */
export function friendlyError(error) {
    if (error.status === 429) return "You're looking words up very fast. Wait a moment and try again.";
    if (error.status === 401 || error.status === 419) return 'Your session has expired. Reload the page and sign in again.';

    return error.message;
}

/**
 * Read the word aloud with the browser's own English voice (no network, no cost).
 */
export function speak(text) {
    if (!('speechSynthesis' in window)) {
        return false;
    }

    window.speechSynthesis.cancel();
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = 'en-GB';
    utterance.rate = 0.9;
    window.speechSynthesis.speak(utterance);

    return true;
}
