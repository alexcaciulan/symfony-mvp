import { Controller } from '@hotwired/stimulus';

// Closes a Preline modal on connect, then removes itself. Appended by a Turbo
// Stream so a modal-triggered action can dismiss its modal after the page
// updates in place (no full reload).
export default class extends Controller {
    static values = { targetId: String };

    connect() {
        const id = this.targetIdValue;
        const el = document.getElementById(id);

        if (el && window.HSOverlay && typeof window.HSOverlay.close === 'function') {
            window.HSOverlay.close(el);
        } else if (el) {
            // Preline v4 does not expose window.HSOverlay; click a close trigger
            // inside the modal (data-hs-overlay) to dismiss it, same fallback as
            // auto-modal_controller.
            const closeTrigger = el.querySelector(`[data-hs-overlay="#${id}"]`);
            if (closeTrigger instanceof HTMLElement) {
                closeTrigger.click();
            }
        }

        this.element.remove();
    }
}
