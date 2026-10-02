import { Controller } from '@hotwired/stimulus';

/*
 * Hides the disagreements an ANAF sync has just settled.
 *
 * The sync writes the register's name and seat into the party's fields, and the
 * server drops the conflicts on those fields once the step is submitted with the
 * sync recorded. Hiding them right away keeps the panel from inviting the lawyer
 * to pick a document's address over the register's, and disabling their inputs
 * keeps a choice made before the sync from being posted. Undoing the sync brings
 * them back.
 */
export default class extends Controller {
    static targets = ['item'];

    /** Mirrors CaseWizardController::ANAF_SETTLED_FIELDS. */
    static SETTLED_FIELDS = ['name', 'address', 'county', 'locality'];

    anafSynced(event) {
        this.toggle(event.detail, true);
    }

    anafUnsynced(event) {
        this.toggle(event.detail, false);
    }

    toggle({ scope, entity } = {}, settled) {
        for (const item of this.itemTargets) {
            if (item.dataset.scope !== scope || !this.constructor.SETTLED_FIELDS.includes(item.dataset.field)) {
                continue;
            }
            if ((entity || '') !== (item.dataset.entity || '')) {
                continue;
            }
            item.hidden = settled;
            item.querySelectorAll('input').forEach((input) => { input.disabled = settled; });
        }

        // Nothing left to decide: the panel itself goes.
        const visible = this.itemTargets.some((item) => !item.hidden);
        this.element.hidden = !visible;
    }
}
