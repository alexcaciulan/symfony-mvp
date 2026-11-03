import { Controller } from '@hotwired/stimulus';

// One exclude dialog for every position: the button that opens it passes the
// route and the position's label, so the dialog posts to the right position.
export default class extends Controller {
    static targets = ['form', 'label'];

    open(event) {
        const { url, label } = event.params;
        if (this.hasFormTarget && url) {
            this.formTarget.setAttribute('action', url);
            this.formTarget.reset();
        }
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = label || '';
        }
    }
}
