import { Controller } from '@hotwired/stimulus';

// Populates the shared edit-deadline modal from whichever card's edit button was
// clicked. Document-level delegation so it works across DOM subtrees (the button
// lives in the deadline panel, the modal lives at body level so a Turbo Stream
// panel re-render never destroys the open modal / orphans its backdrop).
export default class extends Controller {
    static targets = ['form', 'date', 'description', 'criticalWarning'];

    connect() {
        this._handler = (event) => this._populate(event);
        document.addEventListener('click', this._handler);
    }

    disconnect() {
        document.removeEventListener('click', this._handler);
    }

    _populate(event) {
        const btn = event.target.closest('[data-deadline-edit-url]');
        if (!btn) return;

        this.formTarget.setAttribute('action', btn.dataset.deadlineEditUrl);
        this.dateTarget.value = btn.dataset.deadlineEditDate || '';
        this.descriptionTarget.value = btn.dataset.deadlineEditDescription || '';

        if (this.hasCriticalWarningTarget) {
            this.criticalWarningTarget.classList.toggle('hidden', btn.dataset.deadlineEditCritical !== '1');
        }
    }
}
