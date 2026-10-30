/**
 * Switches the admin sidebar between the default collapsed icon rail (admin.css's
 * `html:not(.lp-admin-sidebar-expanded)` rules) and the classic full-width sidebar.
 *
 * The choice is saved on the account so it follows the user across browsers. The
 * layout header renders the saved mode on <html> (and marks it with
 * data-lp-sidebar-saved), so the account wins whenever it has a value. localStorage
 * only covers an account that never chose, and a save that failed.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'lpAdminSidebarMode';
    var EXPANDED_CLASS = 'lp-admin-sidebar-expanded';

    function storedExpanded() {
        try {
            return window.localStorage.getItem(STORAGE_KEY) === 'expanded';
        } catch (error) {
            return false;
        }
    }

    function persistLocally(expanded) {
        try {
            window.localStorage.setItem(STORAGE_KEY, expanded ? 'expanded' : 'collapsed');
        } catch (error) {
            // Storage unavailable (private browsing, disabled): the toggle still
            // works for this page, it just won't be remembered here.
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

        var root = document.documentElement;
        var accountHasChoice = root.hasAttribute('data-lp-sidebar-saved');
        var expanded = accountHasChoice ? root.classList.contains(EXPANDED_CLASS) : storedExpanded();
        var token = buttons[0].getAttribute('data-lp-sidebar-mode-token') || '';
        var url = buttons[0].getAttribute('data-lp-sidebar-mode-url') || '';
        var saving = false;

        applyState(buttons, expanded);

        function save(value) {
            var body = new URLSearchParams();
            body.set('form', 'sidebar_mode_toggle');
            body.set('sidebar_mode', value ? 'expanded' : 'collapsed');
            body.set('csrf_token', token);

            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
                body: body
            }).then(function (response) {
                return response.json().then(function (data) {
                    // Tokens are single use, so every answer carries the next one.
                    if (data && data.csrf_token) {
                        token = data.csrf_token;
                    }

                    return response.ok && data && data.ok === true;
                });
            });
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (saving) {
                    return;
                }

                expanded = !expanded;
                applyState(buttons, expanded);
                saving = true;

                save(expanded).then(function (saved) {
                    if (saved) {
                        root.setAttribute('data-lp-sidebar-saved', '1');
                    } else {
                        persistLocally(expanded);
                    }
                }).catch(function () {
                    persistLocally(expanded);
                }).then(function () {
                    saving = false;
                });
            });
        });
    });
})();
