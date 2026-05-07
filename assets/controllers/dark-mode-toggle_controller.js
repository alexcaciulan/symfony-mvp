import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'lex-color-scheme';

export default class extends Controller {
    static targets = ['icon'];

    connect() {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored === 'dark' || stored === 'light') {
            this._apply(stored);
        } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            this._apply('dark');
        } else {
            this._apply('light');
        }
    }

    toggle() {
        const next = document.documentElement.classList.contains('dark') ? 'light' : 'dark';
        this._apply(next);
        localStorage.setItem(STORAGE_KEY, next);
    }

    _apply(mode) {
        if (mode === 'dark') {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
        this._renderIcon(mode);
    }

    _renderIcon(mode) {
        if (!this.hasIconTarget) return;
        this.iconTarget.dataset.mode = mode;
    }
}
