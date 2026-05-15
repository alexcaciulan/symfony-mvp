import { Controller } from '@hotwired/stimulus';

// Opens a Preline modal programmatically on connect. Used to surface
// post-action modals (e.g. C4 communication memento after generating somația)
// without requiring a user click. Waits briefly for Preline autoInit to run
// after Turbo/morphdom swaps the DOM.
export default class extends Controller {
    static values = { targetId: String, delay: { type: Number, default: 120 } };

    connect() {
        this._timeoutId = window.setTimeout(() => this._open(), this.delayValue);
    }

    disconnect() {
        if (this._timeoutId) {
            window.clearTimeout(this._timeoutId);
            this._timeoutId = null;
        }
    }

    _open() {
        const id = this.targetIdValue;
        if (!id) return;
        const el = document.getElementById(id);
        if (!el) return;

        if (window.HSOverlay && typeof window.HSOverlay.open === 'function') {
            window.HSOverlay.open(el);
            return;
        }

        const trigger = document.querySelector(`[data-hs-overlay="#${id}"]`);
        if (trigger instanceof HTMLElement) {
            trigger.click();
        }
    }
}
