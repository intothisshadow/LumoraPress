/**
 * LP-096: the mobile off-canvas sidebar drawer. Below admin.css's 782px
 * breakpoint, .lp-admin__sidebar becomes a fixed-position drawer that's
 * off-screen by default (see admin.css); this script is what actually
 * opens/closes it, via a single .lp-mobile-nav-open class toggled on
 * .lp-admin__shell that both the sidebar and its backdrop key off of.
 *
 * Deliberately does not persist open/closed state anywhere (unlike
 * nav-toggle.js's per-section localStorage) — admin navigation here is
 * ordinary full page loads, not client-side routing, so "start closed on
 * every page" is simply correct and needs no memory.
 */
(function () {
    'use strict';

    var MOBILE_BREAKPOINT = 782;

    document.addEventListener('DOMContentLoaded', function () {
        var shell = document.querySelector('.lp-admin__shell');
        var toggle = document.querySelector('[data-lp-mobile-nav-toggle]');
        var backdrop = document.querySelector('[data-lp-mobile-nav-backdrop]');
        var sidebar = document.getElementById('lp-admin-mobile-sidebar');

        if (!shell || !toggle || !backdrop || !sidebar) {
            return;
        }

        function isOpen() {
            return shell.classList.contains('lp-mobile-nav-open');
        }

        function open() {
            shell.classList.add('lp-mobile-nav-open');
            toggle.setAttribute('aria-expanded', 'true');
            // Move focus into the drawer so a keyboard/screen-reader user
            // isn't left on a now-hidden-behind-the-backdrop toggle button.
            var firstLink = sidebar.querySelector('a, button');

            if (firstLink) {
                firstLink.focus();
            }
        }

        function close(options) {
            shell.classList.remove('lp-mobile-nav-open');
            toggle.setAttribute('aria-expanded', 'false');

            // Skipped when close() runs because the viewport grew past the
            // breakpoint (see the resize listener below) — the toggle
            // button is display:none there, so focusing it would silently
            // drop focus back to <body>.
            if (!options || options.returnFocus !== false) {
                toggle.focus();
            }
        }

        toggle.addEventListener('click', function () {
            if (isOpen()) {
                close();
            } else {
                open();
            }
        });

        backdrop.addEventListener('click', function () {
            close();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && isOpen()) {
                close();
            }
        });

        window.addEventListener('resize', function () {
            if (isOpen() && window.innerWidth > MOBILE_BREAKPOINT) {
                close({ returnFocus: false });
            }
        });
    });
})();
