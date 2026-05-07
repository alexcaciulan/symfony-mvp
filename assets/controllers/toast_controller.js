import { Controller } from '@hotwired/stimulus';

const VARIANT_CLASSES = {
    success: 'bg-emerald-50 border-emerald-200 text-emerald-800 dark:bg-emerald-900/30 dark:border-emerald-700 dark:text-emerald-100',
    error: 'bg-red-50 border-red-200 text-red-800 dark:bg-red-900/30 dark:border-red-700 dark:text-red-100',
    warning: 'bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-900/30 dark:border-amber-700 dark:text-amber-100',
    info: 'bg-blue-50 border-blue-200 text-blue-800 dark:bg-blue-900/30 dark:border-blue-700 dark:text-blue-100',
};

export default class extends Controller {
    static values = {
        timeout: { type: Number, default: 4000 },
    };

    connect() {
        this._handler = (event) => this.show(event.detail || {});
        window.addEventListener('toast:show', this._handler);
    }

    disconnect() {
        window.removeEventListener('toast:show', this._handler);
    }

    show({ message, variant = 'info' }) {
        if (!message) return;
        const classes = VARIANT_CLASSES[variant] || VARIANT_CLASSES.info;
        const node = document.createElement('div');
        node.className = `pointer-events-auto border rounded-lg shadow-lg px-4 py-3 mb-2 text-sm flex items-start gap-3 max-w-sm ${classes}`;
        node.setAttribute('role', variant === 'error' ? 'alert' : 'status');
        node.setAttribute('aria-live', variant === 'error' ? 'assertive' : 'polite');
        node.innerHTML = `<span class="flex-1">${this._escape(message)}</span><button type="button" class="opacity-60 hover:opacity-100" aria-label="Închide">&times;</button>`;
        node.querySelector('button').addEventListener('click', () => node.remove());
        this.element.appendChild(node);
        if (this.timeoutValue > 0) {
            setTimeout(() => node.remove(), this.timeoutValue);
        }
    }

    _escape(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}
