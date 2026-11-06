import { Controller } from '@hotwired/stimulus';

// The side panel of the deadlines agenda. A plain panel rather than a Preline overlay:
// Preline closes the previous overlay when another opens, and the confirmation dialog
// must open over this one and leave it in place when cancelled.
//
// Without JavaScript nothing here runs and the row link leads into the case.
export default class extends Controller {
    static targets = ['root', 'aside', 'frame', 'dismiss', 'loading', 'failed'];

    connect() {
        this.lastFocus = null;
        this.onKeydown = (event) => this._keydown(event);
        this.onFailed = (event) => {
            // A form inside the panel fails on its own: the details are still valid.
            if (event.target !== this.frameTarget) return;

            event.preventDefault();
            this._fill(this.failedTarget);
            this._focusClose();
        };
        this.onLoaded = () => this._focusClose();
        this.onBeforeCache = () => this.close();

        // Capture: Preline closes an open dialog on the same key, and by the bubble phase
        // this controller could no longer tell that Escape belonged to it.
        document.addEventListener('keydown', this.onKeydown, true);
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
        this.frameTarget.addEventListener('turbo:frame-missing', this.onFailed);
        this.frameTarget.addEventListener('turbo:fetch-request-error', this.onFailed);
        this.frameTarget.addEventListener('turbo:frame-load', this.onLoaded);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown, true);
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
        this.frameTarget.removeEventListener('turbo:frame-missing', this.onFailed);
        this.frameTarget.removeEventListener('turbo:fetch-request-error', this.onFailed);
        this.frameTarget.removeEventListener('turbo:frame-load', this.onLoaded);
        document.documentElement.style.overflow = '';
    }

    open(event) {
        // A modified click means the lawyer wants the case in another tab.
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;

        event.preventDefault();
        this.lastFocus = event.currentTarget;

        this.frameTarget.removeAttribute('src');
        this._fill(this.loadingTarget);
        this.frameTarget.src = event.params.url;

        this.rootTarget.classList.remove('hidden');
        document.documentElement.style.overflow = 'hidden';
        this._focusClose();
    }

    close() {
        if (this.rootTarget.classList.contains('hidden')) return;

        this.rootTarget.classList.add('hidden');
        document.documentElement.style.overflow = '';
        this.frameTarget.removeAttribute('src');
        this.frameTarget.replaceChildren();

        // After an action the row was rebuilt, so the page region takes the focus.
        const back = this.lastFocus !== null && this.lastFocus.isConnected
            ? this.lastFocus
            : document.getElementById('deadline-agenda');
        if (back !== null) back.focus({ preventScroll: true });
        this.lastFocus = null;
    }

    // Dropped into the page by the agenda stream when an action did not fail.
    dismissTargetConnected(element) {
        element.remove();
        this.close();
    }

    _fill(template) {
        this.frameTarget.replaceChildren(template.content.cloneNode(true));
    }

    _focusClose() {
        const button = this.frameTarget.querySelector('[data-action="deadline-panel#close"]');
        (button ?? this.asideTarget).focus();
    }

    _keydown(event) {
        if (this.rootTarget.classList.contains('hidden')) return;
        // A dialog open over the panel owns the keyboard.
        if (document.querySelector('.hs-overlay.open') !== null) return;

        if (event.key === 'Escape') {
            this.close();
        } else if (event.key === 'Tab') {
            this._keepFocusInside(event);
        }
    }

    _keepFocusInside(event) {
        const controls = [...this.asideTarget.querySelectorAll('a[href], button:not([disabled]), input:not([type=hidden]), select, textarea')]
            .filter((element) => element.offsetParent !== null);
        if (controls.length === 0) return;

        const first = controls[0];
        const last = controls[controls.length - 1];
        const active = document.activeElement;

        if (event.shiftKey && (active === first || !this.asideTarget.contains(active))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (active === last || !this.asideTarget.contains(active))) {
            event.preventDefault();
            first.focus();
        }
    }
}
