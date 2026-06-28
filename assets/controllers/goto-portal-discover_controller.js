import { Controller } from '@hotwired/stimulus';

// Sends the "register case" CTAs to the Activitate portal tab and highlights the
// auto-discover search button. The portal discover flow finds the ECRIS number on
// portal.just.ro and registers + activates monitoring in place, so it is the
// priority path over typing the number manually.
export default class extends Controller {
    navigate(event) {
        event.preventDefault();

        const tab = document.getElementById('tab-portal');
        if (tab) tab.click(); // Preline switches the panel; tab-url-sync updates ?tab=

        // The panel was hidden until the click above; wait a tick for layout before
        // scrolling to and pulsing the search button.
        window.setTimeout(() => {
            const search = document.querySelector('[data-portal-discover-search]');
            if (!search) return;

            search.scrollIntoView({ behavior: 'smooth', block: 'center' });
            search.classList.remove('attention-pulse');
            void search.offsetWidth; // reflow so the animation restarts if re-triggered
            search.classList.add('attention-pulse');
        }, 150);
    }
}
