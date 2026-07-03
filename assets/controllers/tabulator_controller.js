/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { TabulatorFull } from 'tabulator-tables';
import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.default.css';
import '../styles/tomselect-theme.css';
import 'tabulator-tables/dist/css/tabulator.min.css';
import '../styles/tabulator-theme.css';
import { resolveFormatter } from '../tabulator_formatters.js';

// Generic, config-driven Tabulator wrapper. All table specifics (columns, data
// URL, page size, toolbar filters) arrive as JSON via the `config` value, built
// server-side from the table definition. Server-side pagination/sort/filter.
export default class extends Controller {
    static targets = ['table', 'filter', 'clear'];
    static values = { config: Object };

    connect() {
        const cfg = this.configValue;
        const mount = this.hasTableTarget ? this.tableTarget : this.element;
        this._tomSelects = [];
        this._searchTimer = null;

        const labels = cfg.labels || {};
        const columns = (cfg.columns || []).map((c) => {
            const column = { title: c.title, field: c.field, headerSort: !!c.sortable };
            if (c.width) column.width = c.width;
            if (c.filterable) {
                if (c.filterType === 'bool') {
                    column.headerFilter = 'tickCross';
                    column.headerFilterParams = { tristate: true };
                    column.headerFilterEmptyCheck = (value) => value === null;
                } else {
                    column.headerFilter = 'input';
                }
            }
            const formatter = resolveFormatter(c.formatter, labels);
            if (formatter) column.formatter = formatter;
            // Action columns render a button: center it and opt out of the
            // default cell ellipsis so the label is never clipped.
            if (c.formatter === 'open_link') {
                column.hozAlign = 'center';
                column.headerHozAlign = 'center';
                column.cssClass = 'tabulator-action-cell';
            }
            return column;
        });

        const options = {
            layout: 'fitColumns',
            columns,
            ajaxURL: cfg.url,
            sortMode: 'remote',
            filterMode: 'remote',
            pagination: true,
            paginationMode: 'remote',
            paginationSize: cfg.pageSize || 25,
            paginationSizeSelector: cfg.pageSizes || [10, 25, 50, 100],
            paginationCounter: 'rows',
            placeholder: cfg.placeholder || '',
            locale: 'default',
            langs: { default: cfg.langs || {} },
            movableColumns: false,
            selectableRows: false,
            // Disable the loading overlay (shadow box + spinner) on each remote request.
            dataLoader: false,
        };
        if (cfg.height) {
            options.height = cfg.height;
        }

        this.table = new TabulatorFull(mount, options);

        this.table.on('dataLoadError', () => {
            const placeholder = mount.querySelector('.tabulator-placeholder-contents');
            if (placeholder && cfg.errorMessage) {
                placeholder.textContent = cfg.errorMessage;
            }
        });

        this.table.on('tableBuilt', () => this._wireToolbar());

        if (this.hasClearTarget) {
            this.clearTarget.addEventListener('click', () => this._clearFilters());
        }
    }

    // Wire external toolbar controls to remote filtering (decoupled from columns).
    _wireToolbar() {
        if (!this.hasFilterTarget) {
            return;
        }
        this.filterTargets.forEach((el) => {
            if (el.dataset.filterType === 'enum') {
                this._tomSelects.push(
                    new TomSelect(el, {
                        plugins: el.multiple ? ['remove_button'] : [],
                        placeholder: el.getAttribute('placeholder') || '',
                        onChange: () => this._applyFilters(),
                    }),
                );
            } else if (el.dataset.filterType === 'autocomplete') {
                const remoteUrl = el.dataset.filterRemoteUrl;
                const hint = (this.configValue.labels && this.configValue.labels.autocomplete_hint) || '';
                const noResults = (this.configValue.labels && this.configValue.labels.autocomplete_no_results) || '';
                this._tomSelects.push(
                    new TomSelect(el, {
                        plugins: ['clear_button'],
                        valueField: 'value',
                        labelField: 'label',
                        searchField: 'label',
                        placeholder: el.getAttribute('placeholder') || '',
                        preload: false,
                        loadThrottle: 300,
                        maxOptions: 50,
                        shouldLoad: (query) => query.length >= 2,
                        load: (query, callback) => {
                            fetch(remoteUrl + '?q=' + encodeURIComponent(query), { headers: { Accept: 'application/json' } })
                                .then((r) => (r.ok ? r.json() : []))
                                .then(callback)
                                .catch(() => callback());
                        },
                        render: {
                            no_results: (data, escape) => {
                                const msg = data.input.length < 2 ? hint : noResults;
                                return msg ? `<div class="no-results">${escape(msg)}</div>` : '';
                            },
                        },
                        onChange: () => this._applyFilters(),
                    }),
                );
            } else if (el.dataset.filterType === 'search') {
                el.addEventListener('input', () => {
                    clearTimeout(this._searchTimer);
                    this._searchTimer = setTimeout(() => this._applyFilters(), 300);
                });
            } else if (el.dataset.filterType === 'date_range') {
                el.addEventListener('change', () => this._applyFilters());
            }
        });
    }

    _applyFilters() {
        const filters = [];
        const dateRanges = {}; // key -> { from, to }
        this.filterTargets.forEach((el) => {
            const key = el.dataset.filterKey;
            // Date-range: two inputs share one key; combine into "from..to".
            if (el.dataset.filterType === 'date_range') {
                const bounds = dateRanges[key] || (dateRanges[key] = { from: '', to: '' });
                const v = (el.value || '').trim();
                if (v !== '') bounds[el.dataset.filterBound] = v;
                return;
            }
            let value;
            if (el.multiple) {
                value = Array.from(el.selectedOptions).map((o) => o.value);
                if (value.length === 0) return;
            } else {
                value = (el.value || '').trim();
                if (value === '') return;
            }
            // `type` is ignored server-side (derived from the table definition).
            filters.push({ field: key, type: 'like', value });
        });
        Object.entries(dateRanges).forEach(([key, r]) => {
            if (r.from !== '' || r.to !== '') {
                filters.push({ field: key, type: 'date_range', value: `${r.from}..${r.to}` });
            }
        });
        this.table.setFilter(filters);
    }

    _clearFilters() {
        this._tomSelects.forEach((ts) => ts.clear(true));
        this.filterTargets.forEach((el) => {
            if (el.dataset.filterType === 'search' || el.dataset.filterType === 'date_range') {
                el.value = '';
            }
        });
        this._applyFilters();
    }

    disconnect() {
        this._tomSelects.forEach((ts) => ts.destroy());
        this._tomSelects = [];
        if (this.table) {
            this.table.destroy();
            this.table = null;
        }
    }
}
