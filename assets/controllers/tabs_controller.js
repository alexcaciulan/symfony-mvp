import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['tab', 'panel'];
    static values = {
        syncHash: { type: Boolean, default: true },
    };

    connect() {
        this._activate(this._initialTabId());
    }

    select(event) {
        event.preventDefault();
        const id = event.currentTarget.dataset.tabId;
        if (!id) return;
        this._activate(id);
        if (this.syncHashValue) {
            history.replaceState(null, '', `#${id}`);
        }
    }

    _initialTabId() {
        const hash = window.location.hash.replace('#', '');
        if (hash && this.tabTargets.some((t) => t.dataset.tabId === hash)) return hash;
        const first = this.tabTargets[0];
        return first ? first.dataset.tabId : null;
    }

    _activate(id) {
        if (!id) return;
        this.tabTargets.forEach((tab) => {
            const active = tab.dataset.tabId === id;
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.classList.toggle('is-active', active);
        });
        this.panelTargets.forEach((panel) => {
            const active = panel.dataset.tabId === id;
            panel.classList.toggle('hidden', !active);
            if (active && panel.dataset.turboFrameSrc && !panel.dataset.loaded) {
                const frame = panel.querySelector('turbo-frame');
                if (frame && !frame.src) frame.src = panel.dataset.turboFrameSrc;
                panel.dataset.loaded = '1';
            }
        });
    }
}
