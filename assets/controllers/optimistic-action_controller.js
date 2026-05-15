/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Optimistic action: toggles a CSS class instantly, fires the configured POST
 * in the background, and on error reverts the class + dispatches a toast.
 *
 * Pas 4.3 — added CSRF token support. Caller passes the token via
 * `data-optimistic-action-csrf-token-value="{{ csrf_token('name') }}"`. The
 * controller sends it both as `X-CSRF-TOKEN` header and as `_token` form
 * field (Symfony's `isCsrfTokenValid` reads the payload, not the header,
 * so the form field is the load-bearing one).
 */
export default class extends Controller {
    static values = {
        url: String,
        method: { type: String, default: 'POST' },
        toggleClass: { type: String, default: 'is-done' },
        csrfToken: { type: String, default: '' },
        // Caller passes translated message via data-optimistic-action-error-message-value="{{ 'stimulus.optimistic.action_failed'|trans }}".
        errorMessage: { type: String, default: 'The action failed. Please retry.' },
    };

    async trigger(event) {
        event.preventDefault();
        const previous = this.element.classList.contains(this.toggleClassValue);
        this.element.classList.toggle(this.toggleClassValue);
        try {
            const body = new FormData();
            if (this.csrfTokenValue) {
                body.append('_token', this.csrfTokenValue);
            }

            const response = await fetch(this.urlValue, {
                method: this.methodValue,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': this.csrfTokenValue,
                    'Accept': 'text/vnd.turbo-stream.html, text/html',
                },
                body,
            });
            if (!response.ok) throw new Error('HTTP ' + response.status);

            const contentType = response.headers.get('Content-Type') || '';
            if (contentType.includes('turbo-stream')) {
                const html = await response.text();
                if (window.Turbo && typeof window.Turbo.renderStreamMessage === 'function') {
                    window.Turbo.renderStreamMessage(html);
                }
            }
        } catch (e) {
            this.element.classList.toggle(this.toggleClassValue, previous);
            window.dispatchEvent(new CustomEvent('toast:show', {
                detail: { message: this.errorMessageValue, variant: 'error' },
            }));
        }
    }
}
