import { Controller } from '@hotwired/stimulus';

// The dialogs of the global deadlines agenda.
//
// They live at page level, outside the regions a Turbo Stream replaces, so an
// action never destroys the dialog that fired it. There is one dialog per kind and
// its content is written from the control that was pressed, rather than one dialog
// per row: forty rows would otherwise carry forty copies of the same markup and
// rebuild all of them on every action.
//
// Without JavaScript nothing here runs and the page still works. The closing forms
// post straight to the route they already point at, and the button that needs a
// communication date stays an ordinary link to the case, where the same dialog
// lives.
export default class extends Controller {
    static targets = [
        'confirmTitle',
        'confirmBody',
        'confirmFacts',
        'confirmWarning',
        'confirmSubmit',
    ];

    static values = { confirmModalId: String };

    connect() {
        // The form waiting for the lawyer's answer, and the one that already got it.
        this.pendingForm = null;
        this.approvedForm = null;
    }

    // Submit of a form that closes a deadline whose miss cannot be undone. The
    // dialog states the sanction and, for the stamp duty, the state of the case, so
    // what is confirmed is read against the record instead of from memory.
    confirmClose(event) {
        const form = event.target.closest('form');
        if (form === null) return;

        if (this.approvedForm === form) {
            this.approvedForm = null;

            return;
        }

        event.preventDefault();
        this.pendingForm = form;

        const params = event.params || {};
        this.confirmTitleTarget.textContent = params.title || '';
        this.confirmBodyTarget.textContent = params.body || '';
        this.confirmSubmitTarget.textContent = params.confirm || '';
        this._renderFacts(params.facts);
        this._renderWarning(params.warning);

        this._open(this.confirmModalIdValue);
    }

    // Answer of the dialog: the original form is submitted untouched, with the
    // route, the token and the hidden context it was rendered with.
    accept() {
        const form = this.pendingForm;
        this.pendingForm = null;
        this._close(this.confirmModalIdValue);

        if (form === null) return;

        this.approvedForm = form;
        form.requestSubmit();
    }

    // A button whose act needs data the agenda does not hold. The dialog that
    // collects it is on this page, so it is pointed at the route of the pressed
    // button and opened, instead of the lawyer being sent into the case.
    openDialog(event) {
        const params = event.params || {};
        if (!params.action || !params.dialog) return;

        const modal = document.getElementById(params.dialog);
        if (modal === null) return;

        const form = modal.querySelector('form');
        if (form === null) return;

        event.preventDefault();
        form.setAttribute('action', params.action);
        this._open(params.dialog);
    }

    _renderFacts(facts) {
        const list = this.confirmFactsTarget;
        list.replaceChildren();

        if (!Array.isArray(facts) || facts.length === 0) {
            list.classList.add('hidden');

            return;
        }

        facts.forEach((fact) => {
            const row = document.createElement('div');
            row.className = 'flex items-baseline justify-between gap-3 py-1';

            const label = document.createElement('dt');
            label.className = 'text-slate-500 dark:text-slate-400';
            label.textContent = fact.label || '';

            const value = document.createElement('dd');
            value.className = 'text-right font-semibold text-slate-800 dark:text-slate-100';
            value.textContent = fact.value || '';

            row.append(label, value);
            list.append(row);
        });

        list.classList.remove('hidden');
    }

    _renderWarning(text) {
        const box = this.confirmWarningTarget;
        box.textContent = text || '';
        box.classList.toggle('hidden', !text);
    }

    _open(id) {
        const el = document.getElementById(id);
        if (el === null) return;

        if (window.HSOverlay && typeof window.HSOverlay.open === 'function') {
            window.HSOverlay.open(el);

            return;
        }

        this._clickTrigger(id);
    }

    _close(id) {
        const el = document.getElementById(id);
        if (el === null) return;

        if (window.HSOverlay && typeof window.HSOverlay.close === 'function') {
            window.HSOverlay.close(el);

            return;
        }

        this._clickTrigger(id);
    }

    // Preline v4 does not expose window.HSOverlay, so the overlay is driven through
    // its own toggle, the same fallback auto-modal and close-modal use. The toggle
    // is a dedicated hidden control, never a button inside the dialog, so opening
    // and closing go through the same known element.
    _clickTrigger(id) {
        const trigger = document.querySelector(`[data-agenda-dialog-trigger="${id}"]`);
        if (trigger instanceof HTMLElement) {
            trigger.click();
        }
    }
}
