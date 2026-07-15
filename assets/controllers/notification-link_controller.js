/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Handles a click on a notification item. Always marks it read in the background
 * (fetch keepalive, so it survives a navigation). If the item points to a
 * different page, the <a> navigates there normally (a smooth Turbo visit). If it
 * points to the page the user is already on, we skip the jarring reload: mark it
 * read in place, close the dropdown, and let the badge reconcile.
 */
export default class extends Controller {
    static values = { url: String, token: String };

    mark(event) {
        const href = this.element.getAttribute('href');
        const samePage = href && this._samePage(href);
        if (samePage) {
            event.preventDefault();
        }

        this._postRead();

        if (samePage) {
            this._markReadInPlace();
            this._closeDropdown();
        }
    }

    _postRead() {
        if (!this.hasUrlValue) return;
        const body = new FormData();
        body.append('_token', this.tokenValue);
        fetch(this.urlValue, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body,
            keepalive: true,
            credentials: 'same-origin',
        })
            .then(() => window.dispatchEvent(new CustomEvent('notifications:changed')))
            .catch(() => {});
    }

    _samePage(href) {
        try {
            const url = new URL(href, window.location.origin);
            // Compare the full path, not just pathname: a link to another tab of the
            // same case (?tab=... / #...) is a real navigation, not "same page".
            return url.pathname + url.search + url.hash
                === window.location.pathname + window.location.search + window.location.hash;
        } catch (_) {
            return false;
        }
    }

    _markReadInPlace() {
        this.element.querySelector('[data-unread-dot]')?.remove();
        this.element.classList.remove('bg-blue-50/40', 'dark:bg-blue-950/20');
        this.element.querySelectorAll('.font-semibold').forEach((el) => {
            el.classList.remove('font-semibold');
            el.classList.add('font-medium');
        });
    }

    _closeDropdown() {
        const dd = this.element.closest('.hs-dropdown');
        if (!dd) return;
        const instance = window.HSDropdown?.getInstance?.(dd);
        if (instance && typeof instance.close === 'function') {
            instance.close();
            return;
        }
        dd.classList.remove('open');
        const menu = dd.querySelector('.hs-dropdown-menu');
        menu?.classList.add('hidden');
    }
}
