/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

// Progressive disclosure + HTML5 `required` toggling for PF/PJ branches in
// wizard Step 1 (creditor) and per-entry Step 2 (debtor). The DTO Callback +
// PRE_SUBMIT listener are the authoritative gate; this controller is UX-only.
export default class extends Controller {
    static targets = ['radio', 'pfOnly', 'pjOnly'];

    connect() {
        this.update();
    }

    update() {
        const selected = this.selectedValue();

        // The wizard templates pre-check the first enum case (PJ — see
        // PersonType.php ordering). Stimulus mirrors that default so the
        // visible branch and the `required` attributes stay in sync from
        // first paint, even before any radio change event fires.
        const isPj = selected === null ? true : selected === 'PJ';
        const isPf = selected === null ? false : selected === 'PF';

        this.pfOnlyTargets.forEach((el) => el.classList.toggle('hidden', !isPf));
        this.pjOnlyTargets.forEach((el) => el.classList.toggle('hidden', !isPj));

        this.applyRequired(isPj ? 'pj' : 'pf');
    }

    applyRequired(activeBranch) {
        // Scan only inputs inside this controller's scope so multiple debtor
        // entries on the same page don't collide.
        const inputs = this.element.querySelectorAll('[data-required-on]');
        inputs.forEach((el) => {
            const target = el.getAttribute('data-required-on');
            if (target === activeBranch) {
                el.setAttribute('required', 'required');
            } else {
                el.removeAttribute('required');
            }
        });
    }

    selectedValue() {
        const checked = this.radioTargets.find((r) => r.checked);
        return checked ? checked.value : null;
    }
}
