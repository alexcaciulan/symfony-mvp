import { Controller } from '@hotwired/stimulus';

/*
 * A link to a form that also works when the form is already on screen, where a plain
 * #anchor does nothing visible: scrolls to it, focuses the field and rings the form briefly.
 */
export default class extends Controller {
    static values = { field: String };

    open(event) {
        const field = document.getElementById(this.fieldValue);
        if (!field) {
            return;
        }
        event.preventDefault();
        const form = field.closest('section') ?? field;
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        form.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
        field.focus({ preventScroll: true });
        form.classList.add('ring-2', 'ring-blue-400');
        clearTimeout(this.timer);
        this.timer = setTimeout(() => form.classList.remove('ring-2', 'ring-blue-400'), 1500);
    }

    disconnect() {
        clearTimeout(this.timer);
    }
}
