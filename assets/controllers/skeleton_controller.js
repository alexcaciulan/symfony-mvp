import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['placeholder', 'content'];

    connect() {
        this._show(this.element.dataset.loading === 'true');
        this._frame = this.element.closest('turbo-frame');
        if (this._frame) {
            this._loadStartHandler = () => this._show(true);
            this._loadEndHandler = () => this._show(false);
            this._frame.addEventListener('turbo:before-fetch-request', this._loadStartHandler);
            this._frame.addEventListener('turbo:frame-load', this._loadEndHandler);
        }
    }

    disconnect() {
        if (this._frame) {
            this._frame.removeEventListener('turbo:before-fetch-request', this._loadStartHandler);
            this._frame.removeEventListener('turbo:frame-load', this._loadEndHandler);
        }
    }

    _show(loading) {
        this.placeholderTargets.forEach((el) => el.classList.toggle('hidden', !loading));
        this.contentTargets.forEach((el) => el.classList.toggle('hidden', loading));
    }
}
