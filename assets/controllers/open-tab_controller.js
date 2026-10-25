import { Controller } from '@hotwired/stimulus';

// A link to another tab of the case page opens that tab in place when its tab
// button is on the page, so the page is not loaded again. Without the button (or
// without JavaScript) the link navigates as usual.
export default class extends Controller {
    static values = { tab: String };

    open(event) {
        const button = document.getElementById(`tab-${this.tabValue}`);
        const panel = document.getElementById(`panel-${this.tabValue}`);
        if (!button || !panel) return;

        event.preventDefault();
        // The tab button does the switch (Preline) and the ?tab= sync (tab-url-sync).
        button.click();
        if (panel.classList.contains('hidden')) {
            document.querySelectorAll('[role="tabpanel"][id^="panel-"]').forEach((other) => {
                other.classList.toggle('hidden', other !== panel);
            });
        }

        const hash = new URL(this.element.href, window.location.href).hash;
        const target = (hash && document.getElementById(hash.slice(1))) || panel;
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}
