import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        message: { type: String, default: 'Sigur vrei să continui?' },
    };

    confirm(event) {
        if (!window.confirm(this.messageValue)) {
            event.preventDefault();
            event.stopPropagation();
        }
    }
}
