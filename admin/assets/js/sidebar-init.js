/**
 * Applies a browser-stored "expanded" sidebar before first paint for an account that
 * never saved a choice. Loaded synchronously in <head>, since sidebar-mode.js is
 * deferred and would otherwise expand the rail only after the collapsed one was drawn.
 * Accounts with a saved choice never load this: the server renders their state.
 */
(function () {
    'use strict';

    try {
        if (window.localStorage.getItem('lpAdminSidebarMode') === 'expanded') {
            document.documentElement.classList.add('lp-admin-sidebar-expanded');
        }
    } catch (error) {
        // Storage unavailable: the server-rendered default stands.
    }
})();
