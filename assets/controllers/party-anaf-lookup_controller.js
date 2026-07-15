/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * ANAF lookup on CUI blur, for either party of a case.
 *
 * Wired per debtor entry in Step 2 and on the creditor in Step 1. The controller
 * root wraps the CUI input + the name / address fields + the ANAF status badge so
 * we can populate them all at once on a successful lookup. The lookup is triggered
 * on `blur` of the CUI field (debounce comes from the user moving focus). The
 * endpoint is `api_anaf_lookup` (see src/Controller/Api/LookupController.php).
 *
 * On success the controller writes:
 *   - companyName  → the [name] target
 *   - composed address (street + nr + city + county) → the [address] target
 *   - county / locality → the [addressCounty] / [addressLocality] targets
 *     (structured: they feed the competent-court resolver for the debtor, and the
 *     stamp-duty payment UAT for the creditor)
 *   - anafStatus value (`ACTIV`/`INACTIV`/`RADIAT`) → the hidden [anafStatus]
 *   - ISO8601 timestamp → the hidden [anafCheckedAt]
 *   - the emerald "ANAF verificat" badge is unhidden
 *
 * Every target is optional, so a party that has no ANAF status field (the creditor)
 * simply omits it.
 *
 * Errors dispatch a `toast:show` event with the translated message so the
 * existing toast_controller.js picks them up.
 */
export default class extends Controller {
    static targets = ['cui', 'name', 'address', 'addressCounty', 'addressLocality', 'anafStatus', 'anafCheckedAt', 'badge', 'spinner'];
    static values = {
        url: String,
        // English fallbacks. User-facing text comes from the template:
        //   data-party-anaf-lookup-invalid-msg-value="{{ 'exception.anaf.cui_invalid'|trans }}"
        //   data-party-anaf-lookup-unavailable-msg-value="{{ 'exception.anaf.unavailable'|trans }}"
        invalidMsg: { type: String, default: 'Invalid CUI.' },
        unavailableMsg: { type: String, default: 'ANAF unavailable. Please retry.' },
    };

    async lookup() {
        const raw = (this.cuiTarget.value || '').toUpperCase().replace(/\s+/g, '').trim();
        if (raw === '') {
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
                const message = payload.error || this.unavailableMsgValue;
                this.dispatchToast('error', message);
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
        if (this.hasAnafStatusTarget && data.anafStatus) {
            this.anafStatusTarget.value = data.anafStatus;
        }
        if (this.hasAnafCheckedAtTarget && data.anafCheckedAt) {
            this.anafCheckedAtTarget.value = data.anafCheckedAt;
        }
        if (this.hasBadgeTarget) {
            this.badgeTarget.classList.remove('hidden');
        }
    }

    setFieldValue(field, value) {
        field.value = value;
        // Inputting via JS doesn't fire `input`; dispatch manually so any
        // Live Component model-binding picks the change up.
        field.dispatchEvent(new Event('input', { bubbles: true }));
    }

    setLoading(loading) {
        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.toggle('hidden', !loading);
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