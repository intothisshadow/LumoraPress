/**
 * Applies a visitor's stored light/dark theme choice (LP-117) and wires up
 * any [data-lp-theme-toggle] button to flip it.
 *
 * Loaded as a normal (non-deferred, non-module) <script src> in <head>,
 * before the theme's stylesheet, so the top-level applyStoredTheme() call
 * below runs and sets the data-theme attribute before the page paints —
 * avoiding a flash of the wrong theme. It must be an external file rather
 * than an inline <script>: this project's Content-Security-Policy
 * script-src has no 'unsafe-inline'/nonce allowance (see
 * ContentSecurityPolicy::defaultDirectives()), so an inline script here
 * would be silently blocked by every browser.
 *
 * A toggle click only ever switches between an explicit "light" and
 * "dark" — never back to "no preference" — mirroring the admin sidebar's
 * own quick theme toggle (admin/views/layout-header.php). Returning to
 * "follow the system" is not exposed by this control.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'lp-theme';

    function applyStoredTheme() {
        try {
            var stored = window.localStorage.getItem(STORAGE_KEY);

            if (stored === 'light' || stored === 'dark') {
                document.documentElement.setAttribute('data-theme', stored);
            }
        } catch (e) {
            // localStorage can throw (private browsing, disabled storage) —
            // fall back to the theme's prefers-color-scheme CSS, same as if
            // nothing had ever been stored.
        }
    }

    applyStoredTheme();

    document.addEventListener('DOMContentLoaded', function () {
        var buttons = document.querySelectorAll('[data-lp-theme-toggle]');

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var currentTheme = document.documentElement.getAttribute('data-theme');
                var isDark = currentTheme === 'dark'
                    || (currentTheme !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                var next = isDark ? 'light' : 'dark';

                document.documentElement.setAttribute('data-theme', next);

                try {
                    window.localStorage.setItem(STORAGE_KEY, next);
                } catch (e) {
                    // The choice won't persist across reloads, but still
                    // applies for the rest of this page view.
                }
            });
        });
    });
})();
