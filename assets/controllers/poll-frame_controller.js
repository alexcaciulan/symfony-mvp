import { Controller } from '@hotwired/stimulus';

/**
 * Self-perpetuating poller attached to a turbo-frame element. While the
 * server-rendered partial says `pending-value="true"`, schedules a periodic
 * partial refresh — fetches the current URL with a `Turbo-Frame` header, the
 * server returns just the matching partial, and we splice it into the DOM
 * in place of the existing frame. No page reload, no scroll jump, no focus
 * loss.
 *
 * Why not `<turbo-frame>.reload()`: in Turbo 7.3 that method requires the
 * `src` attribute, and setting `src` triggers an eager fetch on initial
 * render which conflicts with our server-side inclusion of the partial.
 * Doing the fetch ourselves sidesteps that quirk.
 *
 * Self-perpetuating: after each fetch+replace, Stimulus auto-binds the new
 * controller instance on the freshly inserted element. The new render
 * carries the latest `pending-value` — if work is still in progress the new
 * controller arms another tick; when all documents reach a terminal status
 * the new render comes with `pending-value="false"` and polling stops
 * naturally. No timer survives across swaps.
 *
 * Also listens for the `wizard:refresh-frames` window event so the Mercure
 * fast path (wizard-step0 controller `onMessage`) can trigger an immediate
 * refresh without going through a poll tick.
 *
 * Used in Pas 3.0 wizard step 0 as a fallback for Mercure private topics
 * with JWT-templated subscriptions, which can fail to deliver in some hub
 * configurations. Mercure stays the fast path; this is the safety net.
 */
export default class extends Controller {
    static values = {
        pending: Boolean,
        intervalMs: { type: Number, default: 3000 },
    };

    initialize() {
        this._timer = null;
        this._fetching = false;
        this._refreshHandler = () => { this._refresh(); };
    }

    connect() {
        window.addEventListener('wizard:refresh-frames', this._refreshHandler);
        if (this.pendingValue) {
            this._scheduleNext();
        }
    }

    disconnect() {
        window.removeEventListener('wizard:refresh-frames', this._refreshHandler);
        if (this._timer) {
            clearTimeout(this._timer);
            this._timer = null;
        }
    }

    _scheduleNext() {
        if (this._timer) return;
        this._timer = setTimeout(() => {
            this._timer = null;
            this._refresh();
        }, this.intervalMsValue);
    }

    async _refresh() {
        if (this._fetching) return;
        this._fetching = true;
        try {
            const response = await fetch(window.location.href, {
                headers: {
                    'Turbo-Frame': this.element.id,
                    'Accept': 'text/html, application/xhtml+xml',
                },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                // Network or 5xx — retry on next tick instead of giving up.
                if (this.pendingValue) this._scheduleNext();
                return;
            }
            const html = await response.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newFrame = doc.getElementById(this.element.id);
            if (newFrame) {
                // replaceWith disconnects the current controller and Stimulus
                // re-attaches on the freshly inserted frame. If that new frame
                // still carries pending-value="true", its connect() arms the
                // next tick. We don't re-schedule from here — the new instance
                // owns its lifecycle.
                this.element.replaceWith(newFrame);
                return;
            }
            // Frame not found in response (e.g. server rendered a redirect):
            // back off but keep polling so we don't get stuck.
            if (this.pendingValue) this._scheduleNext();
        } catch (_) {
            // Transient network failure — try again on next tick.
            if (this.pendingValue) this._scheduleNext();
        } finally {
            this._fetching = false;
        }
    }
}
