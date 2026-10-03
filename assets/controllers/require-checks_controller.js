import { Controller } from '@hotwired/stimulus';

/*
 * Keeps a submit button disabled until every required confirmation is ticked,
 * so a submission the server would refuse is not offered in the first place.
 * The hint says what is missing and disappears once nothing is.
 */
export default class extends Controller {
    static targets = ['check', 'submit', 'hint'];

    connect() {
        this.refresh();
    }

    refresh() {
        const ready = this.checkTargets.every((box) => box.checked);
        for (const button of this.submitTargets) {
            button.disabled = !ready;
            button.setAttribute('aria-disabled', String(!ready));
            button.classList.toggle('opacity-50', !ready);
            button.classList.toggle('cursor-not-allowed', !ready);
        }
        for (const hint of this.hintTargets) {
            hint.hidden = ready;
        }
    }
}
