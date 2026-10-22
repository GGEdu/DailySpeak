/**
 * The meanings the word list asks for when a word is opened: which have arrived, by word id, and which requests
 * failed. The page reports what happens to each request; the row then shows the meaning, or says that it could not
 * be loaded and offers to try again, instead of showing "Loading…" for ever.
 *
 * `store` is the object the page renders: { analyses, failed }. It is replaced, not changed, so a reactive store works.
 */
export function createMeanings(store) {
    return {
        // A request starts: a failure of an earlier try is not shown while this one is in flight.
        ask(id) {
            store.failed = { ...store.failed, [id]: false };
        },

        // The server answered. Its explanation counts only when it is the word asked for and has an analysis.
        answer(id, explanation) {
            if (explanation?.id === id && explanation.analysis) {
                store.analyses = { ...store.analyses, [id]: explanation.analysis };
            }
        },

        // The request ended (whatever the answer was): without the analysis of the word, it failed.
        finish(id) {
            if (!(id in store.analyses)) {
                store.failed = { ...store.failed, [id]: true };
            }
        },

        has(id) {
            return id in store.analyses;
        },
    };
}
