import { Controller } from '@hotwired/stimulus';

// A server-rendered dialog: Cancel, Escape or a click on the backdrop removes
// it; focus starts on the safe choice, stays inside, and returns to the opener.
export default class extends Controller {
    static targets = ['panel', 'initialFocus'];

    connect() {
        // Inside the page content a stacking context keeps the header above the
        // backdrop, so the dialog moves to the body. Moving it reconnects the
        // controller, and the second connect does the setup.
        if (this.element.parentElement !== document.body) {
            this.element.returnFocusTo = document.activeElement;
            document.body.appendChild(this.element);
            return;
        }
        this._keydown = (event) => this._onKeydown(event);
        document.addEventListener('keydown', this._keydown);
        if (this.hasInitialFocusTarget) this.initialFocusTarget.focus();
    }

    disconnect() {
        if (this._keydown) document.removeEventListener('keydown', this._keydown);
    }

    dismiss() {
        const opener = this.element.returnFocusTo;
        this.element.remove();
        if (opener instanceof HTMLElement && opener.isConnected) opener.focus();
    }

    backdrop(event) {
        if (this.hasPanelTarget && !this.panelTarget.contains(event.target)) this.dismiss();
    }

    _onKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.dismiss();
            return;
        }
        if (event.key !== 'Tab') return;
        const focusable = Array.from(this.element.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select, textarea'));
        if (focusable.length === 0) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }
}
