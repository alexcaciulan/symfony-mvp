import { Controller } from '@hotwired/stimulus';

// Two small disclosures of the signing card, both usable from the keyboard.
// Menu mode: a <details> holding links; arrows, Home and End move between them,
// Escape and a click outside close it and focus returns to the button. Without
// JavaScript the <details> still opens and shows both links.
// Tooltip mode: the bubble opens on hover, focus or tap, and Escape closes it;
// its text is tied to the button with aria-describedby, so it is read anyway.
export default class extends Controller {
    static targets = ['trigger', 'popup', 'item'];
    static values = { mode: { type: String, default: 'menu' } };

    connect() {
        if (this.modeValue !== 'menu') {
            this.reset = (event) => {
                this.element.removeAttribute('data-closed');
                if (event.type === 'focusout') this.element.removeAttribute('data-open');
            };
            this.element.addEventListener('mouseleave', this.reset);
            this.element.addEventListener('focusout', this.reset);
            return;
        }
        this.outside = (event) => {
            if (this.element.open && !this.element.contains(event.target)) this.close(false);
        };
        document.addEventListener('click', this.outside);
        this.syncExpanded();
    }

    disconnect() {
        if (this.outside) document.removeEventListener('click', this.outside);
        if (this.reset) {
            this.element.removeEventListener('mouseleave', this.reset);
            this.element.removeEventListener('focusout', this.reset);
        }
    }

    // Tooltip mode: a tap toggles the bubble.
    toggle() {
        if (this.element.hasAttribute('data-open')) {
            this.element.removeAttribute('data-open');
            this.element.setAttribute('data-closed', '');
        } else {
            this.element.removeAttribute('data-closed');
            this.element.setAttribute('data-open', '');
        }
    }

    // Menu mode: the native toggle event of the <details>.
    toggled() {
        this.syncExpanded();
        if (this.element.open && this.openedByKey && this.itemTargets.length) this.itemTargets[0].focus();
        this.openedByKey = false;
    }

    keydown(event) {
        if (this.modeValue !== 'menu') {
            if (event.key === 'Escape') {
                this.element.removeAttribute('data-open');
                this.element.setAttribute('data-closed', '');
            }
            return;
        }
        const items = this.itemTargets;
        const index = items.indexOf(document.activeElement);
        switch (event.key) {
            case 'Escape':
                if (!this.element.open) return;
                event.preventDefault();
                this.close(true);
                break;
            case 'Enter':
            case ' ':
                if (document.activeElement === this.triggerTarget && !this.element.open) this.openedByKey = true;
                break;
            case 'ArrowDown':
            case 'ArrowUp': {
                event.preventDefault();
                if (!this.element.open) {
                    this.openedByKey = true;
                    this.element.open = true;
                    return;
                }
                const step = event.key === 'ArrowDown' ? 1 : -1;
                const next = index === -1 ? (step === 1 ? 0 : items.length - 1) : (index + step + items.length) % items.length;
                items[next]?.focus();
                break;
            }
            case 'Home':
            case 'End':
                if (!this.element.open || index === -1) return;
                event.preventDefault();
                items[event.key === 'Home' ? 0 : items.length - 1]?.focus();
                break;
            case 'Tab':
                if (this.element.open && index !== -1) this.close(false);
                break;
            default:
        }
    }

    close(returnFocus) {
        this.element.open = false;
        this.syncExpanded();
        if (returnFocus) this.triggerTarget.focus();
    }

    syncExpanded() {
        if (this.hasTriggerTarget) this.triggerTarget.setAttribute('aria-expanded', this.element.open ? 'true' : 'false');
    }
}
