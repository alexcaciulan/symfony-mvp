/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['panel'];

    connect() {
        this._previousFocus = null;
        this._keydownHandler = (event) => this._onKeydown(event);
        this._clickHandler = (event) => this._onClickOutside(event);
    }

    open() {
        this._previousFocus = document.activeElement;
        this.element.classList.remove('hidden');
        this.element.setAttribute('aria-hidden', 'false');
        document.addEventListener('keydown', this._keydownHandler);
        this.element.addEventListener('click', this._clickHandler);
        this._focusFirst();
    }

    close() {
        this.element.classList.add('hidden');
        this.element.setAttribute('aria-hidden', 'true');
        document.removeEventListener('keydown', this._keydownHandler);
        this.element.removeEventListener('click', this._clickHandler);
        if (this._previousFocus && typeof this._previousFocus.focus === 'function') {
            this._previousFocus.focus();
        }
    }

    disconnect() {
        document.removeEventListener('keydown', this._keydownHandler);
        this.element.removeEventListener('click', this._clickHandler);
    }

    _onKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.close();
            return;
        }
        if (event.key === 'Tab') {
            this._trapFocus(event);
        }
    }

    _onClickOutside(event) {
        if (this.hasPanelTarget && !this.panelTarget.contains(event.target) && this.element.contains(event.target)) {
            this.close();
        }
    }

    _focusFirst() {
        const focusable = this._focusableElements();
        if (focusable.length > 0) focusable[0].focus();
    }

    _trapFocus(event) {
        const focusable = this._focusableElements();
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

    _focusableElements() {
        const root = this.hasPanelTarget ? this.panelTarget : this.element;
        return Array.from(root.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        ));
    }
}