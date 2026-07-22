/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Pas 3.0 wizard step 0 — coordinates the documents list UI.
 *
 * Responsibilities:
 *   1. `submitOnChange` — auto-submit the upload form when files are picked
 *      (form's `data-turbo-frame="step0-dynamic"` causes Turbo to swap the
 *      frame contents on response — no scroll jump, no full page repaint).
 *   2. `onMessage` — receive Mercure `mercure:message` bubbled events from
 *      the per-document subscribers when a terminal status arrives. Reload
 *      both Turbo frames + dispatch a `toast:show` event.
 *
 * Polling fallback (for cases where Mercure doesn't deliver — JWT URI-template
 * matching quirks, EventSource reconnects, hub config edge-cases) lives on the
 * <turbo-frame> elements themselves via the `poll-frame` controller, which
 * re-arms automatically on each frame swap. See `poll-frame_controller.js`.
 *
 * Defensive: `_lastStatus` dedups Mercure event storms; `_reloadScheduled`
 * coalesces near-simultaneous terminal events into one reload.
 */
export default class extends Controller {
    initialize() {
        this._lastStatus = new Map();
        this._reloadScheduled = false;
    }

    submitOnChange(event) {
        const form = event.target.closest('form');
        if (form) form.requestSubmit();
    }

    onMessage(event) {
        const data = event.detail?.data;
        if (!data || typeof data !== 'object') return;
        const { documentId, status } = data;
        if (!documentId || !status) return;

        const previous = this._lastStatus.get(documentId);
        if (previous === status) return;
        this._lastStatus.set(documentId, status);

        // Every status that will not change on its own deserves the swap and the
        // toast. PENDING/PROCESSING/PENDING_RETRY keep the spinner badge that is
        // already rendered server-side; the next terminal event triggers reload.
        const TERMINAL = ['COMPLETED', 'FAILED', 'SKIPPED_BY_POLICY'];
        if (!TERMINAL.includes(status)) {
            return;
        }

        this._emitToast(status);
        this._scheduleReload();
    }

    _emitToast(status) {
        // English fallbacks. Templates wire RO via:
        //   data-toast-completed="{{ 'wizard.step0.toast.completed'|trans }}"
        //   data-toast-failed="{{ 'wizard.step0.toast.failed'|trans }}"
        //   data-toast-skipped="{{ 'wizard.step0.toast.skipped'|trans }}"
        const data = this.element.dataset;
        let toastMessage;
        if (status === 'COMPLETED') {
            toastMessage = data.toastCompleted || 'Document processed';
        } else if (status === 'SKIPPED_BY_POLICY') {
            toastMessage = data.toastSkipped || 'AI extraction is off, please fill in manually';
        } else {
            toastMessage = data.toastFailed || 'Extraction failed, please fill in manually';
        }
        window.dispatchEvent(new CustomEvent('toast:show', {
            detail: { message: toastMessage, variant: status === 'COMPLETED' ? 'success' : 'warning' },
        }));
    }

    _scheduleReload() {
        if (this._reloadScheduled) return;
        this._reloadScheduled = true;
        setTimeout(() => {
            // Signal the poll-frame controllers (attached to step0-dynamic and
            // step0-sidecard) to refresh their content via manual fetch+replace.
            // Going through a window event keeps the refresh mechanism in one
            // place — see poll-frame_controller.js for the fetch logic.
            window.dispatchEvent(new CustomEvent('wizard:refresh-frames'));
            this._reloadScheduled = false;
        }, 400);
    }
}