import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        url: String,
        method: { type: String, default: 'POST' },
        toggleClass: { type: String, default: 'is-done' },
    };

    async trigger(event) {
        event.preventDefault();
        const previous = this.element.classList.contains(this.toggleClassValue);
        this.element.classList.toggle(this.toggleClassValue);
        try {
            const response = await fetch(this.urlValue, {
                method: this.methodValue,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/vnd.turbo-stream.html, text/html',
                },
            });
            if (!response.ok) throw new Error('HTTP ' + response.status);
        } catch (e) {
            this.element.classList.toggle(this.toggleClassValue, previous);
            window.dispatchEvent(new CustomEvent('toast:show', {
                detail: { message: 'Acțiunea a eșuat. Reîncearcă.', variant: 'error' },
            }));
        }
    }
}
