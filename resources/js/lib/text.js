// Plain text helpers for word selection. No DOM and no imports, so `node --test` can run them.

// Same limits as config/lookup.php: longer selections are sentences, not vocabulary.
export const MAX_CHARACTERS = 60;
export const MAX_WORDS = 5;
export const MAX_CONTEXT = 500;

const EDGES = /^[\s.,;:!?"'“”‘’()[\]{}«»—–-]+|[\s.,;:!?"'“”‘’()[\]{}«»—–-]+$/gu;

// Common abbreviations whose full stop does not end a sentence.
const ABBREVIATION = /(?:^|[\s(“"'])(?:Mr|Mrs|Ms|Dr|Prof|St|Mt|Sr|Jr|Gen|Gov|Sen|Rep|Inc|Ltd|Co|Corp|vs|etc|approx|No|e\.g|i\.e|[A-Z](?:\.[A-Z])+)\.$/;

/**
 * Trim spaces and the punctuation a double-click or a sloppy drag picks up.
 */
export function normalise(text) {
    return text.replace(/\s+/gu, ' ').replace(EDGES, '');
}

export function isVocabulary(text) {
    return /\p{L}/u.test(text) && text.length <= MAX_CHARACTERS && text.split(' ').length <= MAX_WORDS;
}

/**
 * The sentence of `text` that contains the characters from `start` to `end`: it decides which
 * meaning gets translated. A full stop after an abbreviation ("U.S.", "Dr.") or a closing quote
 * followed by a lowercase word ("Stop!" he said) does not end the sentence.
 */
export function sentenceAt(text, start, end) {
    // Edge punctuation picked up with the word ("grew.") belongs to the word's own sentence.
    const selected = text.slice(start, end);
    start += selected.length - selected.replace(/^[\s.,;:!?"'“”‘’()—–-]+/u, '').length;
    end -= selected.length - selected.replace(/[\s.,;:!?"'“”‘’()—–-]+$/u, '').length;

    const boundary = /[.!?…]["”’)]*\s+(?=["“‘(]?[\p{Lu}\d])/gu;
    let from = 0;
    let to = text.length;

    for (const match of text.matchAll(boundary)) {
        if (ABBREVIATION.test(text.slice(Math.max(0, match.index - 12), match.index + 1))) {
            continue;
        }

        const after = match.index + match[0].length;
        if (after <= start) {
            from = after;
        } else if (match.index >= end - 1) {
            to = match.index + match[0].trimEnd().length;
            break;
        }
    }

    // No punctuation in sight: keep a window around the selection rather than the block's start.
    if (to - from > MAX_CONTEXT) {
        from = Math.max(from, start - Math.floor((MAX_CONTEXT - (end - start)) / 2));
        to = Math.min(to, from + MAX_CONTEXT);
    }

    return Array.from(text.slice(from, to).replace(/\s+/gu, ' ').trim()).join('');
}
