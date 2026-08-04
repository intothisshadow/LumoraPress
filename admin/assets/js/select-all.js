/**
 * Generic "select all" checkbox enhancement (LP-068, and a pre-existing
 * latent bug on the All Posts screen fixed alongside it): toggles every
 * checkbox of a given name within the nearest ancestor matching a given
 * selector. Implemented as an external, addEventListener-driven script
 * rather than an inline onclick attribute because Lumora Press's default
 * Content-Security-Policy (`script-src 'self'`, no `'unsafe-inline'`, see
 * ContentSecurityPolicy::defaultDirectives()) silently blocks inline
 * event-handler attributes — the checkbox still toggles its own state
 * (native browser behavior), but an inline onclick's JS never runs, so
 * nothing else gets checked.
 *
 * Markup contract:
 *   <input type="checkbox" data-lp-select-all="post_ids[]"
 *          data-lp-select-all-scope="table">
 * toggles every <input type="checkbox" name="post_ids[]"> inside the
 * closest ancestor matching data-lp-select-all-scope (defaults to "form"
 * when omitted).
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-lp-select-all]').forEach(function (selectAll) {
            var scope = selectAll.closest(selectAll.dataset.lpSelectAllScope || 'form');

            if (!scope) {
                return;
            }

            var targetName = selectAll.dataset.lpSelectAll;

            selectAll.addEventListener('change', function () {
                scope.querySelectorAll('input[type="checkbox"][name="' + targetName + '"]').forEach(function (box) {
                    box.checked = selectAll.checked;
                });
            });
        });
    });
}());
