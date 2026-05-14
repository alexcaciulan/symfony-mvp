import { Controller } from '@hotwired/stimulus';

// Re-init Preline after Turbo/LiveComponent connects AND after morphdom DOM
// swaps (no `live:render` event exists, so MutationObserver + 50ms debounce).
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
