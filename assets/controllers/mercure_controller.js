import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        topic: String,
        hub: { type: String, default: '' },
        token: { type: String, default: '' },
        eventName: { type: String, default: 'mercure:message' },
    };

    connect() {
        if (!this.topicValue) return;
        const hub = this.hubValue || (window.MERCURE_PUBLIC_URL || '');
        if (!hub) {
            console.warn('[mercure_controller] no hub URL configured');
            return;
        }
        const url = new URL(hub);
        url.searchParams.append('topic', this.topicValue);
        if (this.tokenValue) {
            url.searchParams.append('authorization', this.tokenValue);
        }
        this._eventSource = new EventSource(url.toString());
        this._eventSource.onmessage = (event) => {
            let data = event.data;
            try { data = JSON.parse(event.data); } catch (_) { /* keep raw */ }
            this.element.dispatchEvent(new CustomEvent(this.eventNameValue, {
                detail: { topic: this.topicValue, data },
                bubbles: true,
            }));
        };
        this._eventSource.onerror = () => {
            // browser auto-reconnects; nothing to do
        };
    }

    disconnect() {
        if (this._eventSource) {
            this._eventSource.close();
            this._eventSource = null;
        }
    }
}
