import { Controller } from '@hotwired/stimulus';

/*
 * Asks for the payment confirmation the moment the lawyer comes back from the
 * electronic registry. The payment link opens the registry in a new tab; when this
 * tab regains focus afterwards, the hidden prompt of the stamp duty card is shown,
 * so the confirmation is asked right then rather than left for the lawyer to find.
 * Shown once per visit: the prompt stays until the page changes.
 */
export default class extends Controller {
    static targets = ['prompt', 'confirmButton'];

    connect() {
        this.armed = false;
        this.left = false;
        // The link was pressed, and only then did this tab lose the focus: that is
        // the registry tab taking over. A focus event fired before that would
        // otherwise show the prompt while the lawyer is still here.
        this.onLeave = () => {
            if (this.armed && (document.hidden || !document.hasFocus())) {
                this.left = true;
            }
        };
        this.onReturn = () => {
            if (this.left && !document.hidden) {
                this.showPrompt();
            }
        };
        window.addEventListener('blur', this.onLeave);
        document.addEventListener('visibilitychange', this.onLeave);
        document.addEventListener('visibilitychange', this.onReturn);
        window.addEventListener('focus', this.onReturn);
    }

    disconnect() {
        window.removeEventListener('blur', this.onLeave);
        document.removeEventListener('visibilitychange', this.onLeave);
        document.removeEventListener('visibilitychange', this.onReturn);
        window.removeEventListener('focus', this.onReturn);
    }

    leave() {
        this.armed = true;
    }

    // Opens the same confirmation dialog as the card's own button, so there is one
    // trigger of that dialog on the page and the prompt only brings it forward.
    confirm() {
        if (this.hasConfirmButtonTarget) {
            this.confirmButtonTarget.click();
        }
    }

    showPrompt() {
        if (!this.hasPromptTarget) {
            return;
        }
        this.promptTarget.classList.remove('hidden');
        this.left = false;
        this.armed = false;
    }
}
