/**
 * Theme Browser (LP-044), Appearance page progressive enhancement.
 *
 * Markup contract (see admin/views/appearance.php):
 *   <input data-lp-theme-search>                      — live search box
 *   <div data-lp-theme-grid>                           — card container
 *     <div data-lp-theme-card data-theme-search="...">  — one per theme
 *       <button data-lp-theme-details-trigger data-theme-template="lp-theme-details-{slug}">
 *   <template id="lp-theme-details-{slug}">             — details panel markup
 *   <p data-lp-theme-empty hidden>                       — "no results" message
 *   <dialog data-lp-theme-dialog>                        — single shared dialog
 *
 * Everything here is optional enhancement: without JavaScript, every card
 * still shows its screenshot, name, and an Activate button that works as a
 * plain form post — only the search box and details dialog are inert.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.querySelector('[data-lp-theme-grid]');
        var dialog = document.querySelector('[data-lp-theme-dialog]');

        if (!grid || !dialog) {
            return;
        }

        var searchInput = document.querySelector('[data-lp-theme-search]');
        var emptyMessage = document.querySelector('[data-lp-theme-empty]');
        var cards = Array.prototype.slice.call(grid.querySelectorAll('[data-lp-theme-card]'));

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var query = searchInput.value.trim().toLowerCase();
                var visibleCount = 0;

                cards.forEach(function (card) {
                    var haystack = card.getAttribute('data-theme-search') || '';
                    var matches = query === '' || haystack.indexOf(query) !== -1;
                    card.hidden = !matches;

                    if (matches) {
                        visibleCount++;
                    }
                });

                if (emptyMessage) {
                    emptyMessage.hidden = visibleCount !== 0;
                }
            });
        }

        grid.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-lp-theme-details-trigger]');

            if (!trigger) {
                return;
            }

            var templateId = trigger.getAttribute('data-theme-template');
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
            var closeTrigger = event.target.closest('[data-lp-theme-dialog-close]');

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
