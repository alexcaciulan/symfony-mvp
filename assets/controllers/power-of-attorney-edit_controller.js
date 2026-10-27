import { Controller } from '@hotwired/stimulus';

// Populates the shared power-of-attorney details modal from whichever trigger was
// clicked (documents list, generation dialog, package card). Document-level
// delegation so it works across DOM subtrees re-rendered by Turbo Streams.
export default class extends Controller {
    static targets = ['form', 'series', 'number', 'date', 'filename'];

    connect() {
        this._handler = (event) => this._populate(event);
        document.addEventListener('click', this._handler);
    }

    disconnect() {
        document.removeEventListener('click', this._handler);
    }

    _populate(event) {
        const btn = event.target.closest('[data-poa-edit-url]');
        if (!btn) return;

        this.formTarget.setAttribute('action', btn.dataset.poaEditUrl);
        this.seriesTarget.value = btn.dataset.poaEditSeries || '';
        this.numberTarget.value = btn.dataset.poaEditNumber || '';
        this.dateTarget.value = btn.dataset.poaEditDate || '';
        if (this.hasFilenameTarget) {
            this.filenameTarget.textContent = btn.dataset.poaEditFilename || '';
        }
    }
}
