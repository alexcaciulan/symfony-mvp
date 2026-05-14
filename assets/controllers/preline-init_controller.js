import { Controller } from '@hotwired/stimulus';

// Re-initializes Preline UI components (accordion / dropdown / modal / etc.)
// on:
//  - first connect (page load)
//  - turbo:load (Turbo navigations)
//  - live:connect (Symfony UX LiveComponent initial mount)
//  - DOM subtree mutations under document.body — Symfony UX LiveComponent uses
//    morphdom to swap children on re-render but does NOT dispatch a DOM event.
//    Without re-init, accordions/modals inside a LC silently stop working after
//    the first data-model update. The MutationObserver fires for every morphed
//    subtree; debounced to coalesce bursts (typing into a debounced data-model
//    field can trigger several mutations in rapid succession).
//
// Performance: HSStaticMethods.autoInit() is idempotent — already-bound nodes
// are skipped. Worst case is a no-op walk; cheap enough to debounce at 50ms.
export default class extends Controller {
    connect() {
        this._handler = () => this._scheduleReinit();
        document.addEventListener('turbo:load', this._handler);
        document.addEventListener('live:connect', this._handler);

        this._observer = new MutationObserver(() => this._scheduleReinit());
        this._observer.observe(document.body, { childList: true, subtree: true });

        this._reinit();
    }

    disconnect() {
        document.removeEventListener('turbo:load', this._handler);
        document.removeEventListener('live:connect', this._handler);

        if (this._observer) {
            this._observer.disconnect();
            this._observer = null;
        }

        if (this._reinitTimeoutId) {
            clearTimeout(this._reinitTimeoutId);
            this._reinitTimeoutId = null;
        }
    }

    _scheduleReinit() {
        if (this._reinitTimeoutId) {
            clearTimeout(this._reinitTimeoutId);
        }
        this._reinitTimeoutId = setTimeout(() => {
            this._reinitTimeoutId = null;
            this._reinit();
        }, 50);
    }

    _reinit() {
        if (window.HSStaticMethods && typeof window.HSStaticMethods.autoInit === 'function') {
            window.HSStaticMethods.autoInit();
        }
    }
}
