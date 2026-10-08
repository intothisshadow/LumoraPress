/**
 * Keeps a color swatch and a hex text field in step, with a "Use default"
 * button that empties the field.
 *
 * <input type="color"> can never be empty and has no alpha, so the text
 * field stays the value that is submitted (it may be empty, or 3, 4, 6 or 8
 * hex digits); the swatch is only a picker for it. No inline handlers or
 * styles, which the admin Content-Security-Policy blocks.
 *
 * Markup contract (see admin/views/settings/maintenance-mode.php):
 *   <span data-lp-color-field data-color-default="#1a1a2e">
 *     <input type="color" data-lp-color-swatch>
 *     <input type="text" data-lp-color-text>
 *     <button type="button" data-lp-color-clear>
 *   </span>
 */
(function () {
    'use strict';

    var HEX = /^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/;

    // The swatch only holds #rrggbb, so shorter forms are expanded and any
    // alpha digits dropped; null means the text is not a color yet.
    function toSwatchValue(text) {
        var match = HEX.exec(text.trim());

        if (!match) {
            return null;
        }

        var digits = match[1];

        if (digits.length <= 4) {
            digits = digits.split('').map(function (digit) { return digit + digit; }).join('');
        }

        return '#' + digits.slice(0, 6).toLowerCase();
    }

    document.querySelectorAll('[data-lp-color-field]').forEach(function (field) {
        var swatch = field.querySelector('[data-lp-color-swatch]');
        var text = field.querySelector('[data-lp-color-text]');
        var clear = field.querySelector('[data-lp-color-clear]');
        var fallback = field.dataset.colorDefault || '#000000';

        if (!swatch || !text) {
            return;
        }

        function sync() {
            var swatchValue = toSwatchValue(text.value);
            var isEmpty = text.value.trim() === '';

            swatch.value = swatchValue !== null ? swatchValue : fallback;
            field.classList.toggle('lp-color-field--empty', isEmpty);

            if (clear) {
                clear.hidden = isEmpty;
            }
        }

        swatch.addEventListener('input', function () {
            text.value = swatch.value;
            sync();
        });

        text.addEventListener('input', sync);

        if (clear) {
            clear.addEventListener('click', function () {
                text.value = '';
                sync();
                text.focus();
            });
        }

        sync();
    });
}());
