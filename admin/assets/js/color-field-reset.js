/**
 * Unchecks a paired "use theme default" checkbox whenever a color field
 * is edited (LP-069). Replaces the inline `oninput="document.getElementById(
 * '...').checked = false;"` attribute that Lumora Press's default
 * Content-Security-Policy (`script-src 'self'`, no `'unsafe-inline'`, see
 * ContentSecurityPolicy::defaultDirectives()) silently blocks.
 *
 * Markup contract (see admin/views/appearance/theme-options.php):
 *   <input type="color" data-lp-color-reset-target="field-id-reset">
 *   <input type="checkbox" id="field-id-reset">
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-lp-color-reset-target]').forEach(function (colorField) {
            var checkbox = document.getElementById(colorField.dataset.lpColorResetTarget);

            if (!checkbox) {
                return;
            }

            colorField.addEventListener('input', function () {
                checkbox.checked = false;
            });
        });
    });
}());
