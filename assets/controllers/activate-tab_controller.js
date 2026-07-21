import { Controller } from '@hotwired/stimulus';

// Finishes a tab switch driven by a Turbo Stream. The streams re-render the tabs
// nav with a new active tab, but a stream cannot toggle the `hidden` class on the
// panels themselves, so without this the nav highlights one tab while another
// panel stays visible. Also syncs `?tab=` so a refresh restores the same tab.
export default class extends Controller {
    static values = { tab: String };

    connect() {
        const tab = this.tabValue;
        if (!tab) return;

        const panels = document.querySelectorAll('[role="tabpanel"][id^="panel-"]');
        if (!panels.length) return;

        panels.forEach((panel) => {
            panel.classList.toggle('hidden', panel.id !== `panel-${tab}`);
        });

        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url);
    }
}
