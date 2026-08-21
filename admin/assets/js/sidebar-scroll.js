/**
 * Restores the admin sidebar's own scroll position across a full page
 * navigation — every admin navigation here reloads the page (not an
 * SPA route change), so without this, scrolling down to reach a
 * sidebar link (a long nav list, e.g. several sections expanded via
 * nav-toggle.js's own "expand all" toggle) landed back at the top of a
 * freshly loaded page after clicking it, undoing that scroll. Reported
 * directly by Ariane. Only matters now that admin.css makes the
 * sidebar its own independently-scrollable, viewport-pinned element
 * (`position: sticky` + `overflow-y: auto`) rather than scrolling away
 * as part of the page.
 *
 * sessionStorage rather than nav-toggle.js's localStorage — this is a
 * same-visit, point-in-time scroll position, not a durable preference
 * like which sections are expanded.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'lpAdminSidebarScrollTop';

    document.addEventListener('DOMContentLoaded', function () {
        var sidebar = document.querySelector('.lp-admin__sidebar');

        if (!sidebar) {
            return;
        }

        try {
            var saved = window.sessionStorage.getItem(STORAGE_KEY);

            if (saved !== null) {
                sidebar.scrollTop = parseInt(saved, 10) || 0;
            }
        } catch (error) {
            // Storage unavailable (private browsing, disabled) — the
            // sidebar just won't remember its scroll position.
        }

        window.addEventListener('beforeunload', function () {
            try {
                window.sessionStorage.setItem(STORAGE_KEY, String(sidebar.scrollTop));
            } catch (error) {
                // Same as above.
            }
        });
    });
})();
