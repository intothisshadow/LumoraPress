/**
 * Generic "submit the form on change" enhancement (LP-069): replaces the
 * inline `onchange="this.form.submit()"` attribute that Lumora Press's
 * default Content-Security-Policy (`script-src 'self'`, no
 * `'unsafe-inline'`, see ContentSecurityPolicy::defaultDirectives())
 * silently blocks.
 *
 * Markup contract:
 *   <select data-lp-auto-submit>...</select>
 * submits the select's owning form whenever its value changes.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-lp-auto-submit]').forEach(function (field) {
            field.addEventListener('change', function () {
                if (field.form) {
                    field.form.submit();
                }
            });
        });
    });
}());
