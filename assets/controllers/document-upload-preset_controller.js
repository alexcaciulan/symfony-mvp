import { Controller } from '@hotwired/stimulus';

// Presets the document-type <select> in the shared upload modal from whichever
// trigger was clicked. Document-level delegation so it works across DOM subtrees
// (the trigger lives in the documents panel / communication warning, the modal
// lives at body level so a Turbo Stream panel re-render never destroys it).
export default class extends Controller {
    static targets = ['type'];

    connect() {
        this._handler = (event) => this._preset(event);
        document.addEventListener('click', this._handler);
    }

    disconnect() {
        document.removeEventListener('click', this._handler);
    }

    _preset(event) {
        const btn = event.target.closest('[data-upload-preset-type]');
        if (!btn || !this.hasTypeTarget) return;

        const value = btn.dataset.uploadPresetType;
        if (value) {
            this.typeTarget.value = value;
        }
    }
}
