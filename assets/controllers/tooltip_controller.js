import { Controller } from '@hotwired/stimulus';

// One bubble for every control of the page, shown at once on hover and on keyboard focus.
// Delegated, so rows rebuilt by a Turbo Stream need no setup; hover from the document, so
// leaving the page body for the sidebar or the topbar still hides it. It
// sits left of the control, so it never covers the row above. Touch screens have no
// hover and the panel carries full labels there.
export default class extends Controller {
    static targets = ['bubble', 'title', 'note'];

    connect() {
        this.hide = () => this._hide();
        this.onOver = (event) => {
            const control = event.target.closest('[data-tip]');
            control ? this._show(control) : this._hide();
        };
        this.onFocus = (event) => {
            const control = event.target.closest('[data-tip]');
            if (control !== null) this._show(control);
        };
        this.onKey = (event) => {
            if (event.key === 'Escape') this._hide();
        };

        if (window.matchMedia('(hover: hover)').matches) {
            document.addEventListener('mouseover', this.onOver);
        }
        this.element.addEventListener('focusin', this.onFocus);
        this.element.addEventListener('focusout', this.hide);
        this.element.addEventListener('click', this.hide);
        document.addEventListener('keydown', this.onKey);
        document.addEventListener('turbo:before-stream-render', this.hide);
        window.addEventListener('scroll', this.hide, true);
    }

    disconnect() {
        document.removeEventListener('mouseover', this.onOver);
        this.element.removeEventListener('focusin', this.onFocus);
        this.element.removeEventListener('focusout', this.hide);
        this.element.removeEventListener('click', this.hide);
        document.removeEventListener('keydown', this.onKey);
        document.removeEventListener('turbo:before-stream-render', this.hide);
        window.removeEventListener('scroll', this.hide, true);
    }

    _show(control) {
        const bubble = this.bubbleTarget;
        const note = control.dataset.tipNote || '';

        this.titleTarget.textContent = control.dataset.tip;
        this.noteTarget.textContent = note;
        this.noteTarget.classList.toggle('hidden', note === '');

        bubble.classList.remove('hidden');
        bubble.style.left = '0px';
        bubble.style.top = '0px';

        const anchor = control.getBoundingClientRect();
        const box = bubble.getBoundingClientRect();

        let left = anchor.left - box.width - 10;
        let top = Math.max(8, Math.min(anchor.top + anchor.height / 2 - box.height / 2, window.innerHeight - box.height - 8));

        if (left < 8) {
            left = Math.max(8, Math.min(anchor.left + anchor.width / 2 - box.width / 2, window.innerWidth - box.width - 8));
            top = anchor.top - box.height - 8;
            if (top < 64) top = anchor.bottom + 8;
        }

        bubble.style.left = `${left}px`;
        bubble.style.top = `${top}px`;
    }

    _hide() {
        this.bubbleTarget.classList.add('hidden');
    }
}
