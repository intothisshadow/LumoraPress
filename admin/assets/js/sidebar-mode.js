/**
 * LP-151: lets a user opt out of the default collapsed/icon sidebar with
 * flyout submenus (admin.css's `html:not(.lp-admin-sidebar-expanded)`
 * rules) in favor of the classic full-width sidebar with LP-092's
 * in-place expand/collapse. Remembered per-browser via localStorage, the
 * same way nav-toggle.js remembers manually-opened sections — this is
 * viewport-driven UI state, not an account-level preference like
 * ThemePreference.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'lpAdminSidebarMode';
    var EXPANDED_CLASS = 'lp-admin-sidebar-expanded';

    function isExpanded() {
        try {
            return window.localStorage.getItem(STORAGE_KEY) === 'expanded';
        } catch (error) {
            return false;
        }
    }

    function persist(expanded) {
        try {
            window.localStorage.setItem(STORAGE_KEY, expanded ? 'expanded' : 'collapsed');
        } catch (error) {
            // Storage unavailable (private browsing, disabled) — the
            // toggle still works for the current page, it just won't
            // persist across page loads.
        }
    }

    function applyState(buttons, expanded) {
        document.documentElement.classList.toggle(EXPANDED_CLASS, expanded);

        buttons.forEach(function (button) {
            button.setAttribute('aria-pressed', expanded ? 'true' : 'false');
            button.setAttribute('aria-label', expanded ? 'Collapse sidebar' : 'Expand sidebar');
            button.textContent = expanded ? '«' : '»';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-lp-sidebar-mode-toggle]'));

        if (buttons.length === 0) {
            return;
        }

        var expanded = isExpanded();
        applyState(buttons, expanded);

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                expanded = !expanded;
                persist(expanded);
                applyState(buttons, expanded);
            });
        });
    });
})();
