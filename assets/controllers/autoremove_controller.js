/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Auto-removes the host element after a delay. Used by Turbo-Stream-appended
 * toast nodes so they fade out without requiring a wrapping `data-controller="toast"`
 * (the toast controller listens for `toast:show` window events and creates its
 * own DOM — but a stream-appended element is already in the DOM and just needs
 * a self-dismiss timer).
 */
export default class extends Controller {
    static values = { timeout: { type: Number, default: 4000 } };

    initialize() {
        this._timer = null;
    }

    connect() {
        if (this.timeoutValue > 0) {
            this._timer = setTimeout(() => this.element.remove(), this.timeoutValue);
        }
    }

    disconnect() {
        if (this._timer) clearTimeout(this._timer);
    }
}