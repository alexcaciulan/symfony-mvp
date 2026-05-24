/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { TabulatorFull } from 'tabulator-tables';
import 'tabulator-tables/dist/css/tabulator.min.css';
import '../styles/tabulator-theme.css';
import { resolveFormatter } from '../tabulator_formatters.js';

// Generic, config-driven Tabulator wrapper. All table specifics (columns, data
// URL, page size) arrive as JSON via the `config` value, built server-side from
// the table definition. Server-side pagination/sort/filter (remote mode).
export default class extends Controller {
    static values = { config: Object };

    connect() {
        const cfg = this.configValue;

        const labels = cfg.labels || {};
        const columns = (cfg.columns || []).map((c) => {
            const column = { title: c.title, field: c.field, headerSort: !!c.sortable };
            if (c.width) column.width = c.width;
            if (c.filterable) {
                if (c.filterType === 'bool') {
                    // Tristate so the default (indeterminate) state applies no filter.
                    column.headerFilter = 'tickCross';
                    column.headerFilterParams = { tristate: true };
                    column.headerFilterEmptyCheck = (value) => value === null;
                } else {
                    column.headerFilter = 'input';
                }
            }
            const formatter = resolveFormatter(c.formatter, labels);
            if (formatter) column.formatter = formatter;
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
            // Disable the loading overlay (shadow box + spinner) on each remote
            // page/sort/filter request; local responses are fast enough.
            dataLoader: false,
        };
        // Auto-height (grows with the page's rows) unless an explicit height is set.
        if (cfg.height) {
            options.height = cfg.height;
        }

        this.table = new TabulatorFull(this.element, options);

        // Surface a clear message instead of an endless loader on ajax failure.
        this.table.on('dataLoadError', () => {
            const placeholder = this.element.querySelector('.tabulator-placeholder-contents');
            if (placeholder && cfg.errorMessage) {
                placeholder.textContent = cfg.errorMessage;
            }
        });
    }

    disconnect() {
        if (this.table) {
            this.table.destroy();
            this.table = null;
        }
    }
}
