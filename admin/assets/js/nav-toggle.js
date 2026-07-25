/**
 * Progressively enhances the Settings/Maintenance parent menu items
 * (admin/views/layout-header.php) with a manual expand/collapse toggle.
 * Without this script, each parent's submenu still shows/hides correctly
 * based on which section is currently active (see admin.css's
 * `.lp-admin__nav-item--parent.is-open` rule) — this only adds the ability
 * to open a non-active section, or collapse the active one, and remembers
 * that choice across page loads (a full navigation, not an SPA route
 * change) via localStorage.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'lpAdminNavCollapsedState';

    function readOverrides() {
        try {
            return JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '{}');
        } catch (error) {
            return {};
        }
    }

    function writeOverrides(overrides) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(overrides));
        } catch (error) {
            // Storage unavailable (private browsing, disabled) — the toggle
            // still works for the current page, it just won't persist.
        }
    }

    function setOpen(item, toggle, open) {
        item.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    document.addEventListener('DOMContentLoaded', function () {
        var overrides = readOverrides();

        Array.prototype.forEach.call(document.querySelectorAll('.lp-admin__nav-item--parent'), function (item) {
            var slug = item.getAttribute('data-menu-slug');
            var toggle = item.querySelector('.lp-admin__nav-toggle');

            if (!toggle || !slug) {
                return;
            }

            if (Object.prototype.hasOwnProperty.call(overrides, slug)) {
                setOpen(item, toggle, overrides[slug]);
            }

            toggle.addEventListener('click', function () {
                var open = !item.classList.contains('is-open');
                setOpen(item, toggle, open);
                overrides[slug] = open;
                writeOverrides(overrides);
            });
        });
    });
})();
