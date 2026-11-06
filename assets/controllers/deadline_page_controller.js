import { Controller } from '@hotwired/stimulus';

// The deadlines agenda as a whole: the frame the selection loads into and the forms around
// it. A selection that comes back as something other than the agenda (an expired session,
// a server error) becomes a full visit. A request that got no answer leaves the page as it
// was and says so.
export default class extends Controller {
    static targets = ['frame'];

    static values = { selectionFailed: String, actionFailed: String };

    connect() {
        this.onMissing = (event) => {
            if (event.target !== this.frameTarget) return;

            event.preventDefault();
            event.detail.visit(event.detail.response);
        };
        this.onFailed = (event) => this._reportFailure(event);
        this.onRendered = (event) => {
            if (event.target === this.frameTarget) this._keepFocusOnPage();
        };
        this.onSubmitted = (event) => {
            // A stream answer never reaches the code that drops the snapshots, and Back
            // would bring the agenda back as it stood before the action.
            if (event.detail.success) window.Turbo.cache.clear();
        };

        this.element.addEventListener('turbo:frame-missing', this.onMissing);
        this.element.addEventListener('turbo:fetch-request-error', this.onFailed);
        this.element.addEventListener('turbo:frame-render', this.onRendered);
        this.element.addEventListener('turbo:submit-end', this.onSubmitted);
    }

    disconnect() {
        this.element.removeEventListener('turbo:frame-missing', this.onMissing);
        this.element.removeEventListener('turbo:fetch-request-error', this.onFailed);
        this.element.removeEventListener('turbo:frame-render', this.onRendered);
        this.element.removeEventListener('turbo:submit-end', this.onSubmitted);
    }

    _reportFailure(event) {
        const isSelection = event.target === this.frameTarget;
        if (!isSelection && !(event.target instanceof HTMLFormElement)) return;

        if (isSelection) event.preventDefault();

        window.dispatchEvent(new CustomEvent('toast:show', {
            detail: {
                message: isSelection ? this.selectionFailedValue : this.actionFailedValue,
                variant: 'error',
            },
        }));
    }

    // The link that was clicked is gone, which would send a keyboard back to the page top.
    _keepFocusOnPage() {
        if (document.activeElement !== null && document.activeElement !== document.body) return;

        document.getElementById('deadline-agenda')?.focus({ preventScroll: true });
    }
}
