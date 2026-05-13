/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * Progressive disclosure for personType (PF vs PJ) in wizard Step 1 (creditor)
 * and Step 2 (debtor). Hides fields that aren't applicable to the selected
 * person type — CNP for PJ, CUI/ONRC/administrator for PF.
 *
 * Markup expected (per form / per debtor entry — controller scope is its root):
 *   <div data-controller="person-type-toggle">
 *     <input type="radio" name="..." value="PF" data-person-type-toggle-target="radio"
 *            data-action="change->person-type-toggle#update">
 *     <input type="radio" name="..." value="PJ" data-person-type-toggle-target="radio"
 *            data-action="change->person-type-toggle#update">
 *
 *     <div data-person-type-toggle-target="pfOnly">...CNP field...</div>
 *     <div data-person-type-toggle-target="pjOnly">...CUI / ONRC fields...</div>
 *   </div>
 *
 * Note: the server-side `PRE_SUBMIT` listener on Step1CreditorType /
 * Step2DebtorEntryType clears the irrelevant fields anyway — this controller
 * is a UX layer, not a security boundary.
 */
export default class extends Controller {
    static targets = ['radio', 'pfOnly', 'pjOnly'];

    connect() {
        this.update();
    }

    update() {
        const selected = this.selectedValue();

        // Hide everything until we know. Once a value is picked, only the
        // matching slice is visible. The radios start unchecked on a fresh
        // load — the page-level effective_person_type defaults to the first
        // choice (PF) for visual styling, but Stimulus toggles based on the
        // ACTUAL checked input. To keep parity with the visual default, fall
        // back to PF when none are checked yet.
        const isPf = selected === null ? true : selected === 'PF';
        const isPj = selected === null ? false : selected === 'PJ';

        this.pfOnlyTargets.forEach((el) => el.classList.toggle('hidden', !isPf));
        this.pjOnlyTargets.forEach((el) => el.classList.toggle('hidden', !isPj));
    }

    selectedValue() {
        const checked = this.radioTargets.find((r) => r.checked);
        return checked ? checked.value : null;
    }
}
