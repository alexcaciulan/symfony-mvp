import { Controller } from '@hotwired/stimulus';

// The Mercure fast path of the invoice reading inside the add dialog: a terminal
// status on the document's topic asks the poll-frame controller on the same
// element to fetch the state now instead of on its next tick.
export default class extends Controller {
    refresh(event) {
        const status = event.detail?.data?.status;
        if (status && !['COMPLETED', 'FAILED', 'SKIPPED_BY_POLICY'].includes(status)) {
            return;
        }
        window.dispatchEvent(new CustomEvent('wizard:refresh-frames'));
    }
}
