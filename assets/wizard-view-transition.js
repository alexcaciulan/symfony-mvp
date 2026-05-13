/*
 * Pas 3.4 — wire View Transitions API to Turbo Frame swaps.
 *
 * Turbo 7.3 (current importmap version) does NOT auto-call
 * `document.startViewTransition()` on frame renders, so the CSS keyframes
 * declared on `::view-transition-old(wizard-frame)` / new(...) never run.
 * Turbo 8+ adds this natively via the `data-turbo-frame-transition` attribute,
 * but pinning forward right now would pull breaking changes (refactored
 * `data-turbo-permanent` semantics, new event names).
 *
 * This listener wraps the render of the `wizard-frame` frame in a
 * `document.startViewTransition()` call when the browser supports it. On
 * unsupported browsers (Firefox <130, Safari <18, Chrome <111) the render
 * runs as before — no regression, just no fade-slide animation.
 *
 * Pattern documented here:
 *   https://turbo.hotwired.dev/handbook/frames#programmatic-navigation
 *   https://github.com/hotwired/turbo/issues/1041#issuecomment-1873470117
 */
document.addEventListener('turbo:before-frame-render', (event) => {
    if (event.target.id !== 'wizard-frame') {
        return;
    }

    const originalRender = event.detail.render;

    // Scroll-to-top happens INSIDE the view-transition callback so both DOM
    // mutation and scroll mutation are captured in a single snapshot pair —
    // the animation fades from old-scroll-position to new-step-at-top without
    // a visible jump. Without `startViewTransition()` support, we still run
    // the scroll (instant) so step transitions don't strand the user
    // mid-page on Firefox / older Safari.
    if (typeof document.startViewTransition !== 'function') {
        event.detail.render = async (currentElement, newElement) => {
            await originalRender(currentElement, newElement);
            window.scrollTo({ top: 0, behavior: 'instant' });
        };
        return;
    }

    event.detail.render = (currentElement, newElement) => {
        return new Promise((resolve) => {
            const transition = document.startViewTransition(async () => {
                await originalRender(currentElement, newElement);
                window.scrollTo({ top: 0, behavior: 'instant' });
            });
            transition.finished.finally(resolve);
        });
    };
});
