import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this._handler = () => this._reinit();
        document.addEventListener('turbo:load', this._handler);
        this._reinit();
    }

    disconnect() {
        document.removeEventListener('turbo:load', this._handler);
    }

    _reinit() {
        if (window.HSStaticMethods && typeof window.HSStaticMethods.autoInit === 'function') {
            window.HSStaticMethods.autoInit();
        }
    }
}
