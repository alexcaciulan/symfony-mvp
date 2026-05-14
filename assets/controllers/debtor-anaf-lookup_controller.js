/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * Pas 3.3 — ANAF lookup on CUI blur.
 *
 * Wired per debtor entry in Step 2 of the wizard. The controller root wraps
 * the CUI input + the name / address fields + the ANAF status badge so we
 * can populate them all at once on a successful lookup. The lookup is
 * triggered on `blur` of the CUI field (debounce comes from the user moving
 * focus). The endpoint is `api_anaf_lookup` (see src/Controller/Api/LookupController.php).
 *
 * On success the controller writes:
 *   - companyName  → the [name] target
 *   - composed address (street + nr + city + county) → the [address] target
 *   - anafStatus value (`ACTIV`/`INACTIV`/`RADIAT`) → the hidden [anafStatus]
 *   - ISO8601 timestamp → the hidden [anafCheckedAt]
 *   - the emerald "ANAF verificat" badge is unhidden
 *
 * Errors dispatch a `toast:show` event with the translated message so the
 * existing toast_controller.js picks them up.
 */
export default class extends Controller {
    static targets = ['cui', 'name', 'address', 'anafStatus', 'anafCheckedAt', 'badge', 'spinner'];
    static values = {
        url: String,
        // English fallbacks — actual user-facing text vine via template:
        //   data-debtor-anaf-lookup-invalid-msg-value="{{ 'exception.anaf.cui_invalid'|trans }}"
        //   data-debtor-anaf-lookup-unavailable-msg-value="{{ 'exception.anaf.unavailable'|trans }}"
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