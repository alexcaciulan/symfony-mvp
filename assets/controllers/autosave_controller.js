/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

// Autosave controller — debounced POST on form `blur`/`change`, with a status
// indicator (saved-at / save error / network error).
//
// Status messages are i18n-aware: pass translated strings from the template
// via Stimulus values, e.g.
//   data-controller="autosave"
//   data-autosave-url-value="{{ path('whatever_autosave') }}"
//   data-autosave-saved-label-value="{{ 'autosave.saved_at'|trans }}"
//   data-autosave-save-error-label-value="{{ 'autosave.save_error'|trans }}"
//   data-autosave-network-error-label-value="{{ 'autosave.network_error'|trans }}"
//
// `savedLabel` is interpolated with `%time%` so the template controls the
// formatting (e.g. "Salvat la %time%" → "Salvat la 14:32"). The English
// defaults below are fallbacks for cases where the template forgets to wire
// the value.
export default class extends Controller {
    static targets = ['indicator'];
    static values = {
        url: String,
        debounce: { type: Number, default: 500 },
        savedLabel: { type: String, default: 'Saved at %time%' },
        saveErrorLabel: { type: String, default: 'Save failed' },
        networkErrorLabel: { type: String, default: 'Network error' },
    };

    connect() {
        this._timer = null;
        this._handler = (event) => this._schedule(event);
        this.element.addEventListener('blur', this._handler, true);
        this.element.addEventListener('change', this._handler, true);
    }

    disconnect() {
        this.element.removeEventListener('blur', this._handler, true);
        this.element.removeEventListener('change', this._handler, true);
        if (this._timer) clearTimeout(this._timer);
    }

    _schedule() {
        if (!this.urlValue) return;
        if (this._timer) clearTimeout(this._timer);
        this._timer = setTimeout(() => this._save(), this.debounceValue);
    }

    async _save() {
        const form = this.element.tagName === 'FORM' ? this.element : this.element.querySelector('form');
        if (!form) return;
        const data = new FormData(form);
        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                body: data,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (response.ok) {
                this._setIndicator(this.savedLabelValue.replace('%time%', this._now()));
            } else {
                this._setIndicator(this.saveErrorLabelValue, true);
            }
        } catch (e) {
            this._setIndicator(this.networkErrorLabelValue, true);
        }
    }

    _setIndicator(text, isError = false) {
        if (!this.hasIndicatorTarget) return;
        this.indicatorTarget.textContent = text;
        this.indicatorTarget.classList.toggle('text-red-600', isError);
        this.indicatorTarget.classList.toggle('text-gray-500', !isError);
    }

    _now() {
        const d = new Date();
        return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    }
}
