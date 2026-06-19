import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.default.css';
import '../styles/tomselect-theme.css';

/*
 * Competent-court picker for wizard step 4.
 *
 * Same UX as the /cases court filter (tabulator_controller.js): a styled Tom
 * Select that loads results remotely ON TYPE (preload: false, 2+ chars,
 * throttled) from `/api/courts-all-lookup?q=` — it never preloads the whole
 * court list. The chosen court id is mirrored into a hidden form input so the
 * Symfony form submits it. The auto-resolved court (if any) is injected as the
 * preselected option via the `selected` value.
 */
export default class extends Controller {
    static targets = ['select', 'hidden'];
    static values = {
        url: String,
        hintMsg: { type: String, default: '' },
        noResultsMsg: { type: String, default: '' },
        selected: { type: Object, default: {} },
    };

    connect() {
        this.ts = new TomSelect(this.selectTarget, {
            plugins: ['clear_button'],
            valueField: 'value',
            labelField: 'label',
            searchField: 'label',
            placeholder: this.selectTarget.getAttribute('placeholder') || '',
            preload: false,
            loadThrottle: 300,
            maxOptions: 50,
            shouldLoad: (query) => query.length >= 2,
            load: (query, callback) => {
                fetch(this.urlValue + '?q=' + encodeURIComponent(query), { headers: { Accept: 'application/json' } })
                    .then((r) => (r.ok ? r.json() : []))
                    .then(callback)
                    .catch(() => callback());
            },
            render: {
                no_results: (data, escape) => {
                    const msg = data.input.length < 2 ? this.hintMsgValue : this.noResultsMsgValue;
                    return msg ? `<div class="no-results">${escape(msg)}</div>` : '';
                },
            },
            onChange: (value) => {
                this.hiddenTarget.value = value || '';
            },
        });

        // Inject + select the auto-resolved court so it shows on load and the
        // hidden input carries its id even if the user doesn't touch the picker.
        const sel = this.selectedValue;
        if (sel && sel.value) {
            this.ts.addOption({ value: String(sel.value), label: sel.label });
            this.ts.setValue(String(sel.value), true);
            this.hiddenTarget.value = String(sel.value);
        }
    }

    disconnect() {
        if (this.ts) {
            this.ts.destroy();
            this.ts = null;
        }
    }
}
