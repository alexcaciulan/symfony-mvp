import { Controller } from '@hotwired/stimulus';

/**
 * Generic show/hide controller for conditional fields.
 *
 * Usage:
 *   <div data-controller="conditional-fields"
 *        data-conditional-fields-show-value="pj">
 *     <select data-conditional-fields-target="source" data-action="change->conditional-fields#toggle">
 *       <option value="pf">PF</option>
 *       <option value="pj">PJ</option>
 *     </select>
 *     <div data-conditional-fields-target="conditional">
 *       <!-- shown when source value matches show-value -->
 *     </div>
 *     <div data-conditional-fields-target="inverse">
 *       <!-- shown when source value does NOT match show-value -->
 *     </div>
 *   </div>
 *
 * For checkboxes:
 *   <div data-controller="conditional-fields"
 *        data-conditional-fields-show-value="checked">
 *     <input type="checkbox" data-conditional-fields-target="source" data-action="change->conditional-fields#toggle">
 *     <div data-conditional-fields-target="conditional">
 *       <!-- shown when checkbox is checked -->
 *     </div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['source', 'conditional', 'inverse'];
    static values = {
        show: String, // value that triggers showing conditional targets
    };

    connect() {
        this.toggle();
    }

    toggle() {
        const sourceEl = this.sourceTarget;
        let currentValue;

        if (sourceEl.type === 'checkbox') {
            currentValue = sourceEl.checked ? 'checked' : '';
        } else if (sourceEl.type === 'radio') {
            // For radio buttons, find the checked one in the same group
            const name = sourceEl.name;
            const checked = this.element.querySelector(`input[name="${name}"]:checked`);
            currentValue = checked ? checked.value : '';
        } else {
            currentValue = sourceEl.value;
        }

        const shouldShow = currentValue === this.showValue;

        this.conditionalTargets.forEach(el => {
            el.classList.toggle('hidden', !shouldShow);
        });

        if (this.hasInverseTarget) {
            this.inverseTargets.forEach(el => {
                el.classList.toggle('hidden', shouldShow);
            });
        }
    }
}
