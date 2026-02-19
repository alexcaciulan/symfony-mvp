import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller for Symfony CollectionType add/remove.
 *
 * Usage:
 *   <div data-controller="collection"
 *        data-collection-prototype-value="..."
 *        data-collection-max-value="3"
 *        data-collection-index-value="1">
 *     <div data-collection-target="container">
 *       <div data-collection-target="item">...</div>
 *     </div>
 *     <button data-action="click->collection#addItem">Add</button>
 *   </div>
 */
export default class extends Controller {
    static targets = ['container', 'item'];
    static values = {
        prototype: String,
        max: { type: Number, default: 10 },
        index: { type: Number, default: 0 },
    };

    addItem() {
        if (this.itemTargets.length >= this.maxValue) {
            return;
        }

        const html = this.prototypeValue.replace(/__defendant__/g, this.indexValue);
        const wrapper = document.createElement('div');
        wrapper.classList.add('border', 'border-gray-200', 'rounded-lg', 'p-4', 'mb-4', 'relative');
        wrapper.setAttribute('data-collection-target', 'item');

        const header = document.createElement('div');
        header.classList.add('flex', 'items-center', 'justify-between', 'mb-4');
        header.innerHTML = `
            <h3 class="text-sm font-semibold text-gray-700">Pârât #${this.itemTargets.length + 1}</h3>
            <button type="button" class="text-sm text-red-600 hover:text-red-800 font-medium" data-action="click->collection#removeItem">Elimină</button>
        `;

        wrapper.appendChild(header);

        const content = document.createElement('div');
        content.innerHTML = html;
        // Move all children from the parsed content into wrapper
        while (content.firstChild) {
            wrapper.appendChild(content.firstChild);
        }

        this.containerTarget.appendChild(wrapper);
        this.indexValue++;
    }

    removeItem(event) {
        const item = event.target.closest('[data-collection-target="item"]');
        if (item && this.itemTargets.length > 1) {
            item.remove();
        }
    }
}
