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
    };

    async lookup() {
        const cui = this.cuiInputTarget.value.trim();
        if (!cui) {
            this.showStatus('Introduceți un CUI.', 'error');
            return;
        }

        this.setLoading(true);
        this.showStatus('Se caută...', 'info');

        try {
            const url = this.urlValue.replace('__CUI__', encodeURIComponent(cui));
            const response = await fetch(url);
            const result = await response.json();

            if (result.success) {
                this.fillFields(result.data);
                this.showStatus('Date completate cu succes.', 'success');
            } else {
                this.showStatus(result.error || 'Eroare la căutare.', 'error');
            }
        } catch {
            this.showStatus('Eroare de rețea. Încercați din nou.', 'error');
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
            this.lookupButtonTarget.textContent = loading ? 'Se caută...' : 'Caută după CUI';
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
