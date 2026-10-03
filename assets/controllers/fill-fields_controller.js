import { Controller } from '@hotwired/stimulus';

/*
 * Writes a set of values into form fields named in the `values` map (field name
 * to value), firing `input` on each so any listener or live model sees the
 * change. Used where one choice fills several fields at once, such as an
 * account and the bank it belongs to.
 */
export default class extends Controller {
    static values = { values: Object };

    fill() {
        for (const [name, value] of Object.entries(this.valuesValue)) {
            const field = document.querySelector(`[name="${CSS.escape(name)}"]`);
            if (!field) {
                continue;
            }
            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }
}
