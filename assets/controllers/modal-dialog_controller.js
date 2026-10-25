import { Controller } from '@hotwired/stimulus';

/*
 * Opens a native <dialog> as a modal, by id, from a button anywhere inside the
 * controller's element; a button inside the dialog closes it. The browser gives
 * the focus trap and Escape, so a destructive action asks for confirmation
 * without depending on the Preline overlay markup.
 */
export default class extends Controller {
    open(event) {
        const dialog = document.getElementById(event.params.id);
        if (dialog && typeof dialog.showModal === 'function' && !dialog.open) {
            dialog.showModal();
        }
    }

    close(event) {
        const dialog = event.target.closest('dialog');
        if (dialog) {
            dialog.close();
        }
    }
}
