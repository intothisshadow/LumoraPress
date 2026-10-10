/**
 * Shows the [data-lp-back-to-top] button once the page has scrolled a
 * little and scrolls back to the top when it is pressed.
 *
 * An external file rather than an inline script because the site's
 * Content-Security-Policy allows no inline script. The button ships with
 * the hidden attribute, so without this script it never appears.
 */
(function () {
    'use strict';

    var SHOW_AFTER = 400;
    var button = document.querySelector('[data-lp-back-to-top]');

    if (!button) {
        return;
    }

    var ticking = false;

    function update() {
        ticking = false;
        button.hidden = window.pageYOffset < SHOW_AFTER;
    }

    window.addEventListener('scroll', function () {
        if (!ticking) {
            ticking = true;
            window.requestAnimationFrame(update);
        }
    }, { passive: true });

    button.addEventListener('click', function () {
        var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
    });

    update();
})();
