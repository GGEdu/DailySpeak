import { ref } from 'vue';
import { postJson } from './http';

// Same limits as config/lookup.php: longer selections are sentences, not vocabulary.
const MAX_CHARACTERS = 60;
const MAX_WORDS = 5;
const MAX_CONTEXT = 500;

/**
 * The open lookup panel: { text, context, anchor } or null. `anchor` is the selection's box
 * in page coordinates, so the panel stays next to it when the page scrolls.
 */
export const activeLookup = ref(null);

const explanations = new Map();

export function openLookup(text, context, rect) {
    activeLookup.value = {
        text,
        context,
        anchor: { top: rect.top + window.scrollY, bottom: rect.bottom + window.scrollY, left: rect.left + window.scrollX, width: rect.width },
    };
}

export function closeLookup() {
    activeLookup.value = null;
}

/**
 * Trim spaces and the punctuation a double-click or a sloppy drag picks up.
 */
export function normalise(text) {
    return text
        .replace(/\s+/g, ' ')
        .replace(/^[\s.,;:!?"'“”‘’()[\]{}«»—–-]+|[\s.,;:!?"'“”‘’()[\]{}«»—–-]+$/g, '');
}

export function isVocabulary(text) {
    return /\p{L}/u.test(text) && text.length <= MAX_CHARACTERS && text.split(' ').length <= MAX_WORDS;
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

    return isVocabulary(text) ? { text, context: sentenceAround(block, range), rect: range.getBoundingClientRect() } : null;
}

function selectableBlock(node) {
    return (node.nodeType === Node.ELEMENT_NODE ? node : node.parentElement)?.closest('[data-selectable]') ?? null;
}

/**
 * The sentence of `block` that contains the selection: it decides which meaning gets translated.
 */
function sentenceAround(block, range) {
    const text = block.textContent;
    const before = document.createRange();
    before.selectNodeContents(block);
    before.setEnd(range.startContainer, range.startOffset);

    const start = before.toString().length;
    const end = start + range.toString().length;
    const boundary = /[.!?…]["”’)]*\s+/g;

    let from = 0;
    let to = text.length;
    for (const match of text.matchAll(boundary)) {
        const after = match.index + match[0].length;
        if (after <= start) {
            from = after;
        } else if (match.index >= end) {
            to = match.index + match[0].trimEnd().length;
            break;
        }
    }

    return text.slice(from, to).replace(/\s+/g, ' ').trim().slice(0, MAX_CONTEXT);
}

/**
 * Translate and explain a selection (answers are kept for the rest of the visit).
 */
export async function explain(text, context, signal) {
    const key = `${text.toLowerCase()}\n${context ?? ''}`;

    if (!explanations.has(key)) {
        explanations.set(key, await postJson('/api/lookups', { text, context }, { signal }));
    }

    return explanations.get(key);
}

export function saveWord(word, context) {
    return postJson('/api/vocabulary', { word, context });
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
