import { Controller } from '@hotwired/stimulus';

// Presets the document-type <select> in the shared upload modal from whichever
// trigger was clicked. Document-level delegation so it works across DOM subtrees
// (the trigger lives in the documents panel / summons communication card, the modal
// lives at body level so a Turbo Stream panel re-render never destroys it).
// Also shows the power-of-attorney fields only for that type: hidden inputs are
// disabled so they are not submitted and the required date never blocks other types.
const POWER_OF_ATTORNEY = 'imputernicire_avocatiala';

export default class extends Controller {
    static targets = ['type', 'powerOfAttorney', 'powerOfAttorneyDate'];

    connect() {
        this._handler = (event) => this._preset(event);
        document.addEventListener('click', this._handler);
        this.toggle();
    }

    disconnect() {
        document.removeEventListener('click', this._handler);
    }

    toggle() {
        if (!this.hasTypeTarget || !this.hasPowerOfAttorneyTarget) return;

        const isPowerOfAttorney = this.typeTarget.value === POWER_OF_ATTORNEY;
        this.powerOfAttorneyTarget.classList.toggle('hidden', !isPowerOfAttorney);
        this.powerOfAttorneyTarget.querySelectorAll('input').forEach((input) => {
            input.disabled = !isPowerOfAttorney;
        });
        if (this.hasPowerOfAttorneyDateTarget) {
            this.powerOfAttorneyDateTarget.required = isPowerOfAttorney;
        }
    }

    _preset(event) {
        const btn = event.target.closest('[data-upload-preset-type]');
        if (!btn || !this.hasTypeTarget) return;

        const value = btn.dataset.uploadPresetType;
        if (value) {
            this.typeTarget.value = value;
        }
        // A programmatic value change fires no change event.
        this.toggle();
    }
}
