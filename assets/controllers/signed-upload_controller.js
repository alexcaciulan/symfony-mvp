import { Controller } from '@hotwired/stimulus';

// One row of the signing card. Choosing a file sends it at once; a file dropped
// on the row goes to this row only, never to another one, because the internal
// identifier that would tie a file to its document can be missing. Without
// JavaScript the form keeps its submit button and posts the classic way.
export default class extends Controller {
    static targets = ['form', 'input', 'label', 'labelText', 'fallback', 'overlay', 'icon', 'busyIcon', 'busyPill', 'statusPills', 'panel'];
    static values = { panelId: String, checking: String };

    connect() {
        this.busy = false;
        this.depth = 0;
        this.fallbackTargets.forEach((button) => { button.hidden = true; });
        const hash = window.location.hash.slice(1);
        if (hash && (hash === this.element.id || hash === this.panelIdValue)) this.revealPanel(false);
    }

    submit() {
        if (this.busy || !this.hasInputTarget || this.inputTarget.files.length === 0) return;
        // Guarded: a drop also fires change, and each send counts against the hourly limit.
        this.busy = true;
        this.formTarget.requestSubmit();
    }

    started() {
        this.busy = true;
        this.toggleBusy(true);
    }

    ended() {
        // A replaced card disconnects this controller; otherwise the row stays and is restored.
        this.busy = false;
        this.toggleBusy(false);
        if (this.hasInputTarget) this.inputTarget.value = '';
    }

    dragEnter(event) {
        if (!this.carriesFiles(event)) return;
        event.preventDefault();
        this.depth += 1;
        if (this.hasOverlayTarget) this.overlayTarget.hidden = false;
    }

    dragOver(event) {
        if (!this.carriesFiles(event)) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = this.hasInputTarget && !this.busy ? 'copy' : 'none';
    }

    dragLeave(event) {
        if (!this.carriesFiles(event)) return;
        this.depth = Math.max(0, this.depth - 1);
        if (this.depth === 0 && this.hasOverlayTarget) this.overlayTarget.hidden = true;
    }

    drop(event) {
        if (!this.carriesFiles(event)) return;
        event.preventDefault();
        this.depth = 0;
        if (this.hasOverlayTarget) this.overlayTarget.hidden = true;
        const file = event.dataTransfer.files[0];
        if (!file || !this.hasInputTarget || this.busy) return;
        const transfer = new DataTransfer();
        transfer.items.add(file);
        this.inputTarget.files = transfer.files;
        this.submit();
    }

    openPanel(event) {
        if (this.revealPanel(true)) event.preventDefault();
    }

    revealPanel(focus) {
        const panel = document.getElementById(this.panelIdValue);
        if (!panel) return false;
        // The folded list of a signed package opens too, or the row would stay hidden.
        for (let node = this.element.parentElement; node; node = node.parentElement) {
            if (node.tagName === 'DETAILS') node.open = true;
        }
        if (panel.tagName === 'DETAILS') panel.open = true;
        panel.scrollIntoView({ block: 'nearest', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        if (focus) {
            const summary = panel.querySelector('summary') || panel.querySelector('button, a[href]');
            if (summary) summary.focus({ preventScroll: true });
        }
        return true;
    }

    toggleBusy(on) {
        if (this.hasIconTarget) this.iconTarget.hidden = on;
        if (this.hasBusyIconTarget) this.busyIconTarget.hidden = !on;
        if (this.hasStatusPillsTarget) this.statusPillsTarget.hidden = on;
        if (this.hasBusyPillTarget) this.busyPillTarget.hidden = !on;
        if (this.hasLabelTarget) {
            this.labelTarget.classList.toggle('pointer-events-none', on);
            this.labelTarget.classList.toggle('opacity-70', on);
            this.labelTarget.setAttribute('aria-disabled', on ? 'true' : 'false');
        }
        if (this.hasLabelTextTarget) {
            if (on) {
                this.labelText = this.labelTextTarget.textContent;
                this.labelTextTarget.textContent = this.checkingValue;
            } else if (this.labelText !== undefined) {
                this.labelTextTarget.textContent = this.labelText;
            }
        }
    }

    carriesFiles(event) {
        return Array.from(event.dataTransfer?.types || []).includes('Files');
    }
}
