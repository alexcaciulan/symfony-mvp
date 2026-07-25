/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Keeps the topbar notification badge live by polling the durable unread-count
 * endpoint (no Mercure). Backs off while the tab is hidden and reconciles on
 * focus / Turbo navigation. When the count rises, raises a client toast via the
 * shared `toast:show` event (tier/text decided server-side, passed in as a value).
 *
 * The server renders the initial badge, so the count is correct on first paint;
 * this controller only reconciles it over time and after the user acts elsewhere.
 */
export default class extends Controller {
    static targets = ['badge', 'panel'];
    static values = {
        unreadCountUrl: String,
        dropdownUrl: String,
        interval: { type: Number, default: 45000 },
        newMessage: { type: String, default: '' },
    };

    initialize() {
        this._timer = null;
        this._fetching = false;
        this._loadingPanel = false;
        this._panelLoaded = false;
        this._wasOpen = false;
        this._known = this._readBadge();
        this._onVisibility = () => this._handleVisibility();
        this._onFocus = () => this._refresh();
        this._onChanged = () => this._refresh();
    }

    connect() {
        document.addEventListener('visibilitychange', this._onVisibility);
        window.addEventListener('focus', this._onFocus);
        document.addEventListener('turbo:load', this._onFocus);
        window.addEventListener('notifications:changed', this._onChanged);
        this._schedule();

        // Load the dropdown panel from the server on each open. Doing it here
        // instead of a lazy turbo-frame avoids the frame reverting to its
        // placeholder after Turbo navigations / cache restores.
        if (this.hasPanelTarget && this.hasDropdownUrlValue) {
            this._observer = new MutationObserver(() => this._syncOpenState());
            this._observer.observe(this.element, { attributes: true, attributeFilter: ['class'] });
        }
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this._onVisibility);
        window.removeEventListener('focus', this._onFocus);
        document.removeEventListener('turbo:load', this._onFocus);
        window.removeEventListener('notifications:changed', this._onChanged);
        this._clear();
        if (this._observer) {
            this._observer.disconnect();
            this._observer = null;
        }
    }

    _syncOpenState() {
        const isOpen = this.element.classList.contains('open');
        if (isOpen && !this._wasOpen) {
            this._wasOpen = true;
            this._loadPanel();
        } else if (!isOpen) {
            this._wasOpen = false;
        }
    }

    async _loadPanel() {
        if (this._loadingPanel) return;
        this._loadingPanel = true;
        try {
            const res = await fetch(this.dropdownUrlValue, {
                // Mark as XHR so an unauthenticated background poll (tab left open
                // after logout / session expiry) does not get saved by the firewall
                // as the post-login target path, redirecting the next login to JSON.
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!res.ok) return;
            // Keep prior content visible until the fresh markup is ready (no flicker
            // on reopen); only the very first open shows the loading placeholder.
            this.panelTarget.innerHTML = await res.text();
            this._panelLoaded = true;
            this._refresh();
        } catch (_) {
            // Leave whatever is shown; the next open retries.
        } finally {
            this._loadingPanel = false;
        }
    }

    _handleVisibility() {
        if (document.hidden) {
            this._clear();
        } else {
            this._refresh();
            this._schedule();
        }
    }

    _schedule() {
        if (this._timer || document.hidden) return;
        this._timer = setTimeout(() => {
            this._timer = null;
            this._refresh().finally(() => this._schedule());
        }, this.intervalValue);
    }

    _clear() {
        if (this._timer) {
            clearTimeout(this._timer);
            this._timer = null;
        }
    }

    async _refresh() {
        if (this._fetching || !this.hasUnreadCountUrlValue) return;
        this._fetching = true;
        try {
            const res = await fetch(this.unreadCountUrlValue, {
                // XHR marker keeps this background poll from becoming the saved
                // target path when the session is gone; without it the next login
                // lands on this JSON endpoint instead of the dashboard.
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!res.ok) return;
            const data = await res.json();
            const count = Number(data.count) || 0;
            if (count > this._known && this.newMessageValue) {
                window.dispatchEvent(new CustomEvent('toast:show', {
                    detail: { message: this.newMessageValue, variant: 'info' },
                }));
            }
            this._known = count;
            this._render(count);
        } catch (_) {
            // Transient failure — the next tick reconciles.
        } finally {
            this._fetching = false;
        }
    }

    _render(count) {
        if (!this.hasBadgeTarget) return;
        const badge = this.badgeTarget;
        badge.textContent = count > 9 ? '9+' : String(count);
        badge.classList.toggle('hidden', count <= 0);
    }

    _readBadge() {
        if (!this.hasBadgeTarget) return 0;
        const raw = (this.badgeTarget.textContent || '').trim();
        return raw.endsWith('+') ? 9 : (Number(raw) || 0);
    }
}
