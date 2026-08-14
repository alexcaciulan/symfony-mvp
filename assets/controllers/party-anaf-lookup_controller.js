/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * ANAF lookup for either party of a case, triggered by an explicit button.
 *
 * Wired per debtor entry in Step 2 and on the creditor in Step 1. The controller
 * root wraps the CUI input + the name / address fields + the ANAF badge so we can
 * populate them all at once on a successful lookup. The endpoint is
 * `api_anaf_lookup` (see src/Controller/Api/LookupController.php).
 *
 * The trigger is the [syncButton] target, NOT the CUI `blur` event: an
 * extraction-prefilled CUI never receives focus, so a blur trigger skipped
 * exactly the cases that needed it, while incidental focus-outs spent the
 * 10/hour rate-limit budget on lookups nobody asked for.
 *
 * On success the controller writes:
 *   - companyName  → the [name] target
 *   - the street-level address (street, number, block, staircase, floor,
 *     apartment, postal code) → the [address] target. It carries neither the
 *     locality nor the county, which have their own fields.
 *   - county / locality → the [addressCounty] / [addressLocality] targets
 *     (structured: they feed the competent-court resolver for the debtor, and the
 *     stamp-duty payment UAT for the creditor)
 *   - anafStatus value (`ACTIV`/`INACTIV`/`RADIAT`) → the hidden [anafStatus]
 *   - ISO8601 timestamp → the hidden [anafCheckedAt]
 *   - the emerald "synced" badge is unhidden, next to an undo button that
 *     restores the values the sync overwrote
 *
 * Every target is optional, so a party that has no ANAF status field (the creditor)
 * simply omits it.
 *
 * Errors dispatch a `toast:show` event with the translated message so the
 * existing toast_controller.js picks them up. The endpoint answers with i18n KEYS,
 * never prose, so each key is mapped to a template-provided translation here.
 */
export default class extends Controller {
    static targets = ['cui', 'name', 'address', 'addressCounty', 'addressLocality', 'anafStatus', 'anafCheckedAt', 'badge', 'spinner', 'syncButton', 'undoButton'];
    static values = {
        url: String,
        // English fallbacks. User-facing text comes from the template:
        //   data-party-anaf-lookup-invalid-msg-value="{{ 'exception.anaf.cui_invalid'|trans }}"
        //   data-party-anaf-lookup-unavailable-msg-value="{{ 'exception.anaf.unavailable'|trans }}"
        invalidMsg: { type: String, default: 'Invalid CUI.' },
        unavailableMsg: { type: String, default: 'ANAF unavailable. Please retry.' },
        rateLimitedMsg: { type: String, default: 'Too many ANAF lookups. Try again later.' },
        notFoundMsg: { type: String, default: 'CUI not found in the ANAF registry.' },
        successMsg: { type: String, default: 'Company data retrieved from ANAF.' },
        undoneMsg: { type: String, default: 'Previous values restored.' },
        fiscalDomicileMsg: { type: String, default: 'ANAF holds a different fiscal domicile. Check the unit details by hand.' },
        postalCodeMissingMsg: { type: String, default: 'ANAF has no postal code for this company.' },
    };

    /** Payload key -> target name. The controller writes nothing else. */
    static FIELD_MAP = {
        companyName: 'name',
        address: 'address',
        county: 'addressCounty',
        locality: 'addressLocality',
        anafStatus: 'anafStatus',
        anafCheckedAt: 'anafCheckedAt',
    };

    async lookup() {
        const raw = (this.cuiTarget.value || '').toUpperCase().replace(/\s+/g, '').trim();
        // Now user-initiated: an empty CUI is a mistake worth naming rather
        // than a no-op, since nothing else explains the button doing nothing.
        if (raw === '') {
            this.dispatchToast('error', this.invalidMsgValue);
            this.cuiTarget.focus();
            return;
        }

        // The button can be clicked repeatedly; drop re-entrant calls so one
        // click is one ANAF request (and one rate-limit token).
        if (this.loading) {
            return;
        }

        // Client-side guard, same shape as the LookupController requirements
        // (regex `(RO)?\d{2,10}`). Saves an API round-trip on obvious typos.
        if (!/^(RO)?\d{2,10}$/.test(raw)) {
            this.dispatchToast('error', this.invalidMsgValue);
            return;
        }

        this.setLoading(true);

        try {
            const url = this.urlValue.replace('__CUI__', encodeURIComponent(raw));
            const response = await fetch(url, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                this.dispatchToast('error', this.translateError(payload.error));
                return;
            }

            this.populateFields(payload);
        } catch {
            this.dispatchToast('error', this.unavailableMsgValue);
        } finally {
            this.setLoading(false);
        }
    }

    /*
     * ANAF is the official register, so it wins over whatever the extraction
     * or the lawyer had put in the field. The previous values are kept so the
     * sync stays reversible: the register is occasionally poorer than the
     * contract the case was built from.
     *
     * Same setFieldValue() path for every field, hidden ones included: a bare
     * `.value =` skips the synthetic `input` event, and the Live Component then
     * drops the value on its next re-render (add/remove debtor). A lost
     * anafStatus makes OpAdmissibilityValidator block the case at step 4.
     */
    populateFields(data) {
        this.previousValues = new Map();

        for (const [key, targetName] of Object.entries(this.constructor.FIELD_MAP)) {
            const field = this.fieldFor(targetName);
            if (!field || !data[key]) {
                continue;
            }

            this.previousValues.set(targetName, field.value);
            this.setFieldValue(field, data[key]);
        }

        if (this.hasBadgeTarget) {
            this.badgeTarget.classList.remove('hidden');
        }
        if (this.hasUndoButtonTarget) {
            this.undoButtonTarget.classList.remove('hidden');
        }

        this.dispatchToast('success', this.successMsgValue);

        // Two facts the lawyer has to act on now rather than discover once the
        // somaţie comes back undelivered.
        if (data.fiscalDomicileDiffers) {
            this.dispatchToast('warning', this.fiscalDomicileMsgValue);
        }
        if (data.postalCodeMissing) {
            this.dispatchToast('warning', this.postalCodeMissingMsgValue);
        }
    }

    undo() {
        if (!this.previousValues) {
            return;
        }

        for (const [targetName, value] of this.previousValues) {
            const field = this.fieldFor(targetName);
            if (field) {
                this.setFieldValue(field, value);
            }
        }

        this.previousValues = null;
        if (this.hasUndoButtonTarget) {
            this.undoButtonTarget.classList.add('hidden');
        }
        if (this.hasBadgeTarget) {
            this.badgeTarget.classList.add('hidden');
        }

        this.dispatchToast('info', this.undoneMsgValue);
    }

    fieldFor(targetName) {
        const capitalized = targetName.charAt(0).toUpperCase() + targetName.slice(1);

        return this[`has${capitalized}Target`] ? this[`${targetName}Target`] : null;
    }

    translateError(key) {
        return {
            'exception.anaf.cui_invalid': this.invalidMsgValue,
            'exception.anaf.rate_limited': this.rateLimitedMsgValue,
            'exception.anaf.not_found': this.notFoundMsgValue,
            'exception.anaf.unavailable': this.unavailableMsgValue,
        }[key] ?? this.unavailableMsgValue;
    }

    setFieldValue(field, value) {
        field.value = value;
        // Inputting via JS doesn't fire `input`; dispatch manually so any
        // Live Component model-binding picks the change up.
        field.dispatchEvent(new Event('input', { bubbles: true }));
    }

    setLoading(loading) {
        this.loading = loading;
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.toggle('hidden', !loading);
        }
        if (this.hasSyncButtonTarget) {
            this.syncButtonTarget.disabled = loading;
        }
    }

    dispatchToast(variant, message) {
        // `variant` is the key toast_controller.js reads; sending `type` made
        // every ANAF error render as a blue "info" notice with aria-live
        // polite, indistinguishable from a successful sync.
        this.dispatch('show', {
            target: document.body,
            prefix: 'toast',
            detail: { variant, message },
            bubbles: true,
        });
    }
}