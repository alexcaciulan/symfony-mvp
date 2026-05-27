// Reusable Tabulator cell formatters, shared by every table in the app.
// Add a formatter here once and reference it by key from a column's `formatter`.

// Mirrors the StatusBadge.html.twig palette tokens (CaseStatus::color()).
const STATUS_PALETTE = {
    slate: 'bg-slate-100 text-slate-700',
    gray: 'bg-gray-100 text-gray-700',
    blue: 'bg-blue-100 text-blue-800',
    sky: 'bg-sky-100 text-sky-800',
    indigo: 'bg-indigo-100 text-indigo-800',
    violet: 'bg-violet-100 text-violet-800',
    purple: 'bg-purple-100 text-purple-800',
    amber: 'bg-amber-100 text-amber-800',
    orange: 'bg-orange-100 text-orange-800',
    emerald: 'bg-emerald-100 text-emerald-800',
    green: 'bg-green-100 text-green-800',
    teal: 'bg-teal-100 text-teal-800',
    red: 'bg-red-100 text-red-800',
};

function badge(label, color) {
    const classes = STATUS_PALETTE[color] || STATUS_PALETTE.slate;
    return `<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide ${classes}">${escapeHtml(label)}</span>`;
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
}

function formatDate(value, withTime) {
    if (!value) return '';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return escapeHtml(value);
    const date = d.toLocaleDateString('ro-RO', { day: '2-digit', month: '2-digit', year: 'numeric' });
    if (!withTime) return date;
    const time = d.toLocaleTimeString('ro-RO', { hour: '2-digit', minute: '2-digit' });
    return `${date} ${time}`;
}

// Each factory receives localized labels (from the server config) and returns
// a Tabulator formatter function.
const FORMATTERS = {
    date: () => (cell) => formatDate(cell.getValue(), false),
    datetime: () => (cell) => formatDate(cell.getValue(), true),
    bool: (labels) => (cell) =>
        cell.getValue() ? badge(labels.yes || 'Yes', 'green') : badge(labels.no || 'No', 'slate'),
    // Expects the cell value to be { label, color }.
    status_badge: () => (cell) => {
        const v = cell.getValue() || {};
        return badge(v.label ?? '', v.color ?? 'slate');
    },
};

export function resolveFormatter(name, labels = {}) {
    const factory = FORMATTERS[name];
    return factory ? factory(labels) : null;
}
