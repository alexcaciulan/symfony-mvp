import { Controller } from '@hotwired/stimulus';

// Keeps the `?tab=` query param in sync with the active overview tab, so a page
// refresh restores the tab the user was on. Uses replaceState (no new history
// entry, no reload). Server-side render reads `?tab=` to mark the active tab.
export default class extends Controller {
    connect() {
        this._handler = (event) => this._sync(event);
        this.element.addEventListener('click', this._handler);
    }

    disconnect() {
        this.element.removeEventListener('click', this._handler);
    }

    _sync(event) {
        const tab = event.target.closest('[role="tab"][aria-controls]');
        if (!tab) return;

        const panelId = tab.getAttribute('aria-controls');
        if (!panelId || !panelId.startsWith('panel-')) return;

        const url = new URL(window.location.href);
        url.searchParams.set('tab', panelId.slice('panel-'.length));
        window.history.replaceState({}, '', url);
    }
}
