/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * Pas 3.3 — toggle between "pick existing creditor" (UX Autocomplete) and
 * "fill creditor manually" in Step 1 of the wizard.
 *
 * When the autocomplete select has a value (user picked a creditor from
 * their library), the manual-fields panel is hidden — the controller will
 * persist by id, and the per-field validators relax via the DTO's
 * `Expression` xor constraint. Clearing the select shows the panel back.
 */
export default class extends Controller {
    static targets = ['select', 'manualFields'];

    connect() {
        this.toggle();
    }

    toggle() {
        if (!this.hasSelectTarget || !this.hasManualFieldsTarget) {
            return;
        }
        const picked = this.selectTarget.value && this.selectTarget.value !== '';
        this.manualFieldsTarget.classList.toggle('hidden', picked);
    }
}