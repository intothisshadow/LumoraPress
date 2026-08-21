/**
 * Progressively enhances the Settings/Maintenance parent menu items
 * (admin/views/layout-header.php) with a manual expand/collapse toggle,
 * plus a pair of "expand all / collapse all" buttons (one above the nav,
 * one below it — LP-092) that toggle every parent section at once.
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
    // U+229E (⊞, "expand all") / U+229F (⊟, "collapse all") — symbols only,
    // no visible text, per Ariane's request; the accessible label carries
    // the meaning for screen readers.
    var EXPAND_ALL_SYMBOL = '⊞';
    var COLLAPSE_ALL_SYMBOL = '⊟';

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
        var parents = Array.prototype.slice.call(document.querySelectorAll('.lp-admin__nav-item--parent'));
        var toggleAllButtons = Array.prototype.slice.call(document.querySelectorAll('[data-lp-nav-toggle-all]'));

        function updateToggleAllButtons() {
            var anyCollapsed = parents.some(function (item) {
                return !item.classList.contains('is-open');
            });

            toggleAllButtons.forEach(function (button) {
                button.textContent = anyCollapsed ? EXPAND_ALL_SYMBOL : COLLAPSE_ALL_SYMBOL;
                button.setAttribute('aria-label', anyCollapsed ? 'Expand all menu sections' : 'Collapse all menu sections');
            });
        }

        parents.forEach(function (item) {
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
                updateToggleAllButtons();
            });
        });

        toggleAllButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var anyCollapsed = parents.some(function (item) {
                    return !item.classList.contains('is-open');
                });
                var openAll = anyCollapsed;

                parents.forEach(function (item) {
                    var slug = item.getAttribute('data-menu-slug');
                    var toggle = item.querySelector('.lp-admin__nav-toggle');

                    if (!toggle || !slug) {
                        return;
                    }

                    setOpen(item, toggle, openAll);
                    overrides[slug] = openAll;
                });

                writeOverrides(overrides);
                updateToggleAllButtons();
            });
        });

        updateToggleAllButtons();
    });
})();
