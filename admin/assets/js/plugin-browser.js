/**
 * Plugin Browser (LP-045), Plugins page progressive enhancement.
 * Mirrors admin/assets/js/theme-browser.js's contract, extended with a
 * status filter (All / Active / Inactive / Update Available) alongside
 * the search box — both narrow the same card list together. LP-098
 * added a parallel List (table) rendering plus a Grid/List toggle —
 * search/filter apply to whichever view is currently visible (both are
 * always in the DOM; only one is ever shown).
 *
 * Markup contract (see admin/views/plugins.php):
 *   <input data-lp-plugin-search>                        — live search box
 *   <select data-lp-plugin-filter>                        — status filter
 *   <p data-lp-plugin-view-toggle data-csrf="...">         — Grid/List toggle
 *     <button data-lp-plugin-view-button="grid|list">
 *   <div data-lp-plugin-view-wrapper data-view="grid|list">
 *     <table data-lp-plugin-table>
 *       <tr data-lp-plugin-row data-plugin-search="..." data-plugin-status="active|inactive">
 *     <div data-lp-plugin-grid>                             — card container
 *       <div data-lp-plugin-card data-plugin-search="..." data-plugin-status="active|inactive">
 *   Both rows and cards may carry:
 *       <button data-lp-plugin-details-trigger data-plugin-template="lp-plugin-details-{slug}">
 *   <template id="lp-plugin-details-{slug}">               — details panel markup
 *   <p data-lp-plugin-empty hidden>                         — "no results" message
 *   <dialog data-lp-plugin-dialog>                          — single shared dialog
 *
 * Everything here is optional enhancement: without JavaScript, every card
 * still shows its screenshot, name, and an Activate/Deactivate button that
 * works as a plain form post — only the search/filter controls, view
 * toggle, and details dialog are inert (the server-rendered data-view
 * still picks one CSS-visible layout either way). "Update Available"
 * never matches any card yet — no per-plugin update-checking exists in
 * this pass (see TODO.md's LP-045 entry) — so it currently just filters
 * everything out, same as any other status with no matches.
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
        var table = document.querySelector('[data-lp-plugin-table]');
        var items = Array.prototype.slice.call(grid.querySelectorAll('[data-lp-plugin-card]'));

        if (table) {
            items = items.concat(Array.prototype.slice.call(table.querySelectorAll('[data-lp-plugin-row]')));
        }

        function applyFilters() {
            var query = searchInput ? searchInput.value.trim().toLowerCase() : '';
            var status = filterSelect ? filterSelect.value : 'all';
            var visibleCount = 0;

            items.forEach(function (item) {
                var haystack = item.getAttribute('data-plugin-search') || '';
                var itemStatus = item.getAttribute('data-plugin-status') || '';
                var matchesQuery = query === '' || haystack.indexOf(query) !== -1;
                var matchesStatus = status === 'all' || status === itemStatus;
                var matches = matchesQuery && matchesStatus;

                item.hidden = !matches;

                // Only counted once per plugin (cards and rows always
                // match/mismatch identically for the same plugin) — table
                // rows are skipped here so a plugin visible in one view
                // doesn't get double-counted while the other view is hidden.
                if (matches && item.hasAttribute('data-lp-plugin-card')) {
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

        // LP-098: Grid/List toggle — switches instantly client-side (a
        // data-view attribute on the wrapper, shown/hidden purely via CSS)
        // and persists the choice with a fire-and-forget POST so it's
        // remembered per-user next time this screen loads.
        var viewToggle = document.querySelector('[data-lp-plugin-view-toggle]');
        var viewWrapper = document.querySelector('[data-lp-plugin-view-wrapper]');

        if (viewToggle && viewWrapper) {
            var viewCsrfToken = viewToggle.getAttribute('data-csrf') || '';

            viewToggle.addEventListener('click', function (event) {
                var button = event.target.closest('[data-lp-plugin-view-button]');

                if (!button) {
                    return;
                }

                var mode = button.getAttribute('data-lp-plugin-view-button');

                viewWrapper.setAttribute('data-view', mode);

                Array.prototype.slice.call(viewToggle.querySelectorAll('[data-lp-plugin-view-button]')).forEach(function (candidate) {
                    var isActive = candidate === button;
                    candidate.classList.toggle('lp-button--primary', isActive);
                    candidate.classList.toggle('lp-button--secondary', !isActive);
                    candidate.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                });

                var formData = new FormData();
                formData.append('form', 'set_list_view');
                formData.append('mode', mode);
                formData.append('csrf_token', viewCsrfToken);

                fetch(window.location.href, { method: 'POST', body: formData })
                    .then(function (response) { return response.json(); })
                    .then(function (json) {
                        // Csrf::verify() is single-use — a second toggle
                        // click in the same page load needs the fresh
                        // token this response hands back (matches
                        // content-editor.js's identical pattern).
                        if (json.csrfToken) {
                            viewCsrfToken = json.csrfToken;
                        }
                    })
                    .catch(function () {
                        // Best-effort only — the toggle already applied
                        // instantly above regardless of whether this save
                        // succeeds.
                    });
            });
        }

        // Bound to the wrapper (not just the card grid) so the Details
        // trigger works from the List view's table rows too — both
        // reference the same per-slug <template>.
        (viewWrapper || grid).addEventListener('click', function (event) {
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
