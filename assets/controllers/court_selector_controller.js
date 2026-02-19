import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['county', 'court'];
    static values = {
        url: String, // /case/courts-by-county/
        selected: { type: Number, default: 0 }, // pre-selected court ID
    };

    connect() {
        this.updateCourts();
    }

    async countyChanged() {
        // Clear saved selection when county changes
        this.selectedValue = 0;
        await this.updateCourts();
    }

    async updateCourts() {
        const county = this.countyTarget.value;
        const courtSelect = this.courtTarget;

        courtSelect.innerHTML = '';

        if (!county) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = courtSelect.dataset.placeholder || '-- Selectați instanța --';
            courtSelect.appendChild(option);
            return;
        }

        try {
            const response = await fetch(`${this.urlValue}${encodeURIComponent(county)}`);
            if (!response.ok) throw new Error('Network error');

            const courts = await response.json();

            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = courtSelect.dataset.placeholder || '-- Selectați instanța --';
            courtSelect.appendChild(placeholder);

            courts.forEach(court => {
                const option = document.createElement('option');
                option.value = court.id;
                option.textContent = court.name;
                if (this.selectedValue && court.id === this.selectedValue) {
                    option.selected = true;
                }
                courtSelect.appendChild(option);
            });
        } catch (error) {
            console.error('Failed to load courts:', error);
        }
    }
}
