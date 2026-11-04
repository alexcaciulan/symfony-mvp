import { Controller } from '@hotwired/stimulus';

// Positions of the claim card: the segmented filters, the "show all" past the first
// rows, and each position's calculation opened under its row. Everything is already
// on the page, so nothing is fetched; a Turbo Stream that replaces the card starts
// over from the server render.
export default class extends Controller {
    static targets = ['position', 'breakdown', 'filterButton', 'showAll', 'showAllLabel', 'showAllMore'];
    static values = {
        limit: { type: Number, default: 8 },
        paged: Boolean,
        showAllLabel: String,
        showAllMore: String,
    };

    connect() {
        this.kind = 'all';
        this.expanded = false;
    }

    filter(event) {
        this.kind = event.params.kind || 'all';
        this.expanded = false;
        this.filterButtonTargets.forEach((button) => {
            button.setAttribute('aria-pressed', String(button.dataset.claimPositionsKindParam === this.kind));
        });
        this.render();
    }

    showAll() {
        this.expanded = true;
        this.render();
    }

    toggle(event) {
        const id = event.params.id;
        const breakdown = this.breakdownTargets.find((element) => element.id === `claim-breakdown-${id}`);
        if (!breakdown) return;
        this.setOpen(breakdown, breakdown.classList.contains('hidden'));
    }

    render() {
        const matching = this.positionTargets.filter((position) => this.kind === 'all' || position.dataset.filterState === this.kind);
        const limited = this.pagedValue && !this.expanded && matching.length > this.limitValue;
        const visible = new Set(limited ? matching.slice(0, this.limitValue) : matching);

        this.positionTargets.forEach((position) => {
            const shown = visible.has(position);
            position.classList.toggle('hidden', !shown);
            if (!shown) {
                const breakdown = position.querySelector('[data-claim-positions-target="breakdown"]');
                if (breakdown) this.setOpen(breakdown, false);
            }
        });

        if (this.hasShowAllTarget) {
            this.showAllTarget.classList.toggle('hidden', !limited);
            if (this.hasShowAllLabelTarget) {
                this.showAllLabelTarget.textContent = this.showAllLabelValue.replace('__COUNT__', String(matching.length));
            }
            if (this.hasShowAllMoreTarget) {
                this.showAllMoreTarget.textContent = this.showAllMoreValue.replace('__MORE__', String(matching.length - this.limitValue));
            }
        }
    }

    setOpen(breakdown, open) {
        breakdown.classList.toggle('hidden', !open);
        const position = breakdown.closest('[data-claim-positions-target="position"]');
        if (position) position.toggleAttribute('data-open', open);
        this.element.querySelectorAll(`[aria-controls="${breakdown.id}"]`).forEach((trigger) => {
            trigger.setAttribute('aria-expanded', String(open));
        });
    }
}
