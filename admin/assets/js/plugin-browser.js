/**
 * Plugin Browser (LP-045), Plugins page progressive enhancement.
 * Mirrors admin/assets/js/theme-browser.js's contract, extended with a
 * status filter (All / Active / Inactive / Update Available) alongside
 * the search box — both narrow the same card list together.
 *
 * Markup contract (see admin/views/plugins.php):
 *   <input data-lp-plugin-search>                        — live search box
 *   <select data-lp-plugin-filter>                        — status filter
 *   <div data-lp-plugin-grid>                             — card container
 *     <div data-lp-plugin-card data-plugin-search="..." data-plugin-status="active|inactive">
 *       <button data-lp-plugin-details-trigger data-plugin-template="lp-plugin-details-{slug}">
 *   <template id="lp-plugin-details-{slug}">               — details panel markup
 *   <p data-lp-plugin-empty hidden>                         — "no results" message
 *   <dialog data-lp-plugin-dialog>                          — single shared dialog
 *
 * Everything here is optional enhancement: without JavaScript, every card
 * still shows its screenshot, name, and an Activate/Deactivate button that
 * works as a plain form post — only the search/filter controls and details
 * dialog are inert. "Update Available" never matches any card yet — no
 * per-plugin update-checking exists in this pass (see TODO.md's LP-045
 * entry) — so it currently just filters everything out, same as any other
 * status with no matches.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.querySelector('[data-lp-plugin-grid]');
        var dialog = document.querySelector('[data-lp-plugin-dialog]');

        if (!grid || !dialog) {
            return;
        }

        var searchInput = document.querySelector('[data-lp-plugin-search]');
        var filterSelect = document.querySelector('[data-lp-plugin-filter]');
        var emptyMessage = document.querySelector('[data-lp-plugin-empty]');
        var cards = Array.prototype.slice.call(grid.querySelectorAll('[data-lp-plugin-card]'));

        function applyFilters() {
            var query = searchInput ? searchInput.value.trim().toLowerCase() : '';
            var status = filterSelect ? filterSelect.value : 'all';
            var visibleCount = 0;

            cards.forEach(function (card) {
                var haystack = card.getAttribute('data-plugin-search') || '';
                var cardStatus = card.getAttribute('data-plugin-status') || '';
                var matchesQuery = query === '' || haystack.indexOf(query) !== -1;
                var matchesStatus = status === 'all' || status === cardStatus;
                var matches = matchesQuery && matchesStatus;

                card.hidden = !matches;

                if (matches) {
                    visibleCount++;
                }
            });

            if (emptyMessage) {
                emptyMessage.hidden = visibleCount !== 0;
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', applyFilters);
        }

        if (filterSelect) {
            filterSelect.addEventListener('change', applyFilters);
        }

        grid.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-lp-plugin-details-trigger]');

            if (!trigger) {
                return;
            }

            var templateId = trigger.getAttribute('data-plugin-template');
            var template = templateId ? document.getElementById(templateId) : null;

            if (!(template instanceof HTMLTemplateElement)) {
                return;
            }

            dialog.replaceChildren(template.content.cloneNode(true));

            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', 'open');
            }
        });

        dialog.addEventListener('click', function (event) {
            var closeTrigger = event.target.closest('[data-lp-plugin-dialog-close]');

            if (closeTrigger) {
                dialog.close();

                return;
            }

            // Clicking the ::backdrop fires the click on the <dialog>
            // element itself, never a descendant — that's how native
            // <dialog> distinguishes "outside click" from "inside click".
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });
}());
