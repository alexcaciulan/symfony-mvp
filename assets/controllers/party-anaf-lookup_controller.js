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
 *   - composed address (street + nr + city + county) → the [address] target
 *   - county / locality → the [addressCounty] / [addressLocality] targets
 *     (structured: they feed the competent-court resolver for the debtor, and the
 *     stamp-duty payment UAT for the creditor)
 *   - anafStatus value (`ACTIV`/`INACTIV`/`RADIAT`) → the hidden [anafStatus]
 *   - ISO8601 timestamp → the hidden [anafCheckedAt]
 *   - the emerald "synced" badge is unhidden
 *
 * Every target is optional, so a party that has no ANAF status field (the creditor)
 * simply omits it.
 *
 * Errors dispatch a `toast:show` event with the translated message so the
 * existing toast_controller.js picks them up. The endpoint answers with i18n KEYS,
 * never prose, so each key is mapped to a template-provided translation here.
 */
export default class extends Controller {
    static targets = ['cui', 'name', 'address', 'addressCounty', 'addressLocality', 'anafStatus', 'anafCheckedAt', 'badge', 'spinner', 'syncButton'];
    static values = {
        url: String,
        // English fallbacks. User-facing text comes from the template:
        //   data-party-anaf-lookup-invalid-msg-value="{{ 'exception.anaf.cui_invalid'|trans }}"
        //   data-party-anaf-lookup-unavailable-msg-value="{{ 'exception.anaf.unavailable'|trans }}"
        invalidMsg: { type: String, default: 'Invalid CUI.' },
        unavailableMsg: { type: String, default: 'ANAF unavailable. Please retry.' },
        rateLimitedMsg: { type: String, default: 'Too many ANAF lookups. Try again later.' },
        notFoundMsg: { type: String, default: 'CUI not found in the ANAF registry.' },
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

        // Client-side guard — same shape as the LookupController requirements
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

    populateFields(data) {
        if (this.hasNameTarget && data.companyName) {
            this.setFieldValue(this.nameTarget, data.companyName);
        }
        if (this.hasAddressTarget && data.address) {
            this.setFieldValue(this.addressTarget, data.address);
        }
        if (this.hasAddressCountyTarget && data.county) {
            this.setFieldValue(this.addressCountyTarget, data.county);
        }
        if (this.hasAddressLocalityTarget && data.locality) {
            this.setFieldValue(this.addressLocalityTarget, data.locality);
        }
        // Same setFieldValue() path as the visible fields: a bare `.value =`
        // skips the synthetic `input` event, and the Live Component then drops
        // both on its next re-render (add/remove debtor). A lost anafStatus
        // makes OpAdmissibilityValidator block the case at step 4.
        if (this.hasAnafStatusTarget && data.anafStatus) {
            this.setFieldValue(this.anafStatusTarget, data.anafStatus);
        }
        if (this.hasAnafCheckedAtTarget && data.anafCheckedAt) {
            this.setFieldValue(this.anafCheckedAtTarget, data.anafCheckedAt);
        }
        if (this.hasBadgeTarget) {
            this.badgeTarget.classList.remove('hidden');
        }
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

    dispatchToast(type, message) {
        this.dispatch('show', {
            target: document.body,
            prefix: 'toast',
            detail: { type, message },
            bubbles: true,
        });
    }
}