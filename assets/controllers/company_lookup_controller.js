import { Controller } from '@hotwired/stimulus';

/**
 * CUI lookup controller — fetches company data from ANAF via backend endpoint.
 *
 * Usage:
 *   <div data-controller="company-lookup"
 *        data-company-lookup-url-value="/case/company-lookup/__CUI__">
 *     <input data-company-lookup-target="cuiInput">
 *     <button data-action="company-lookup#lookup"
 *             data-company-lookup-target="lookupButton">Caută</button>
 *     <p data-company-lookup-target="statusMessage"></p>
 *     <input data-company-lookup-target="companyNameField">
 *     <input data-company-lookup-target="nameField">
 *     <!-- address fields with matching targets -->
 *   </div>
 */
export default class extends Controller {
    static targets = [
        'cuiInput', 'lookupButton', 'statusMessage',
        'companyNameField', 'nameField',
        'streetField', 'streetNumberField',
        'cityField', 'countyField', 'postalCodeField',
        'phoneField',
    ];

    static values = {
        url: String,
        // All UI strings translated by the caller via data-* attributes.
        buttonLabel: { type: String, default: 'Search' },
        emptyInput: { type: String, default: 'Please enter a tax ID.' },
        searching: { type: String, default: 'Searching...' },
        successMessage: { type: String, default: 'Done.' },
        errorGeneric: { type: String, default: 'Search error.' },
        errorNetwork: { type: String, default: 'Network error. Please try again.' },
    };

    async lookup() {
        const cui = this.cuiInputTarget.value.trim();
        if (!cui) {
            this.showStatus(this.emptyInputValue, 'error');
            return;
        }

        this.setLoading(true);
        this.showStatus(this.searchingValue, 'info');

        try {
            const url = this.urlValue.replace('__CUI__', encodeURIComponent(cui));
            const response = await fetch(url);
            const result = await response.json();

            if (result.success) {
                this.fillFields(result.data);
                this.showStatus(this.successMessageValue, 'success');
            } else {
                this.showStatus(result.error || this.errorGenericValue, 'error');
            }
        } catch {
            this.showStatus(this.errorNetworkValue, 'error');
        } finally {
            this.setLoading(false);
        }
    }

    fillFields(data) {
        this.setFieldValue('companyNameField', data.companyName);
        this.setFieldValue('nameField', data.companyName);
        this.setFieldValue('streetField', data.street);
        this.setFieldValue('streetNumberField', data.streetNumber);
        this.setFieldValue('cityField', data.city);
        this.setFieldValue('countyField', data.county);
        this.setFieldValue('postalCodeField', data.postalCode);
        this.setFieldValue('phoneField', data.phone);
    }

    setFieldValue(targetName, value) {
        const hasTarget = `has${targetName.charAt(0).toUpperCase()}${targetName.slice(1)}Target`;
        if (this[hasTarget]) {
            const target = this[`${targetName}Target`];
            if (value) {
                target.value = value;
                target.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
    }

    setLoading(loading) {
        if (this.hasLookupButtonTarget) {
            this.lookupButtonTarget.disabled = loading;
            this.lookupButtonTarget.textContent = loading ? this.searchingValue : this.buttonLabelValue;
        }
        if (this.hasCuiInputTarget) {
            this.cuiInputTarget.disabled = loading;
        }
    }

    showStatus(message, type) {
        if (!this.hasStatusMessageTarget) return;

        const el = this.statusMessageTarget;
        el.textContent = message;
        el.classList.remove('hidden', 'text-green-600', 'text-red-600', 'text-blue-600');

        const colorClass = {
            success: 'text-green-600',
            error: 'text-red-600',
            info: 'text-blue-600',
        }[type] || 'text-gray-600';

        el.classList.add(colorClass);
    }
}
