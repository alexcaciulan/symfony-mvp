import { Controller } from '@hotwired/stimulus';

/*
 * Brings the first validation error into view after a wizard step submit fails,
 * so an inline error (e.g. the mandatory BPI checkbox at the bottom of the
 * debtor card) isn't left off-screen when Turbo re-renders the frame at scroll
 * top. Uses `turbo:frame-load` (fires once the new content is in the DOM;
 * `turbo:submit-end` races the render). Scoped to `form [role="alert"]` so it
 * targets validation errors and skips the confirmation step's out-of-form
 * admissibility banner on plain loads.
 */
export default class extends Controller {
    onFrameLoad() {
        const alert = this.element.querySelector('form [role="alert"]');
        if (!alert) {
            return;
        }

        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        alert.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });

        // Focus the field's own control, but only when the alert belongs to a
        // single field: a form-level / collection banner resolves its wrapper to
        // the <form>, and focusing its first input would land on an unrelated
        // field. Skip hidden and sr-only controls (radio cards) too — focusing an
        // invisible input is the very problem this feature avoids.
        const wrapper = alert.closest('div')?.parentElement;
        if (!wrapper || wrapper.tagName === 'FORM') {
            return;
        }
        wrapper.querySelector('input:not([type="hidden"]):not(.sr-only), select, textarea')
            ?.focus({ preventScroll: true });
    }
}
