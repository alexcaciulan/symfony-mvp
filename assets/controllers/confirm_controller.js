/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        // Caller must provide via data-confirm-message-value (use a translated string).
        // Empty default is intentional — surfaces missing wiring instead of silently using a hardcoded language.
        message: { type: String, default: '' },
    };

    confirm(event) {
        if (!window.confirm(this.messageValue)) {
            event.preventDefault();
            event.stopPropagation();
        }
    }
}