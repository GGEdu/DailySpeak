import { router } from '@inertiajs/vue3';

// The component on screen. Unknown until the first visit: the first page is not a change.
let component = null;

/**
 * A new page usually takes the focus with it (the link or button that was used is gone), and the
 * focus then falls back to <body>: a screen reader starts from the top of the document. Move it to
 * the new page's heading instead.
 *
 * Imported by the layouts and registered once, when the module loads, so that the visit which
 * swaps one layout for another is seen too.
 */
router.on('navigate', (event) => {
    const next = event.detail.page.component;
    const changed = component !== null && next !== component;
    component = next;

    // Reloads and filters on the same page keep their focus where it is.
    if (!changed) {
        return;
    }

    setTimeout(() => {
        // Never take the focus from something the learner has just reached.
        if (document.activeElement && document.activeElement !== document.body) {
            return;
        }

        const heading = document.querySelector('main h1') ?? document.querySelector('main');

        if (heading) {
            heading.tabIndex = -1;
            heading.focus({ preventScroll: true });
        }
    }, 0);
});
