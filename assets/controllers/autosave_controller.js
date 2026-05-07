import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['indicator'];
    static values = {
        url: String,
        debounce: { type: Number, default: 500 },
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
                this._setIndicator(`Salvat la ${this._now()}`);
            } else {
                this._setIndicator('Eroare la salvare', true);
            }
        } catch (e) {
            this._setIndicator('Eroare de rețea', true);
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
