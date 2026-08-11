/**
 * LP-078 "Custom Structure" live preview on Settings > Permalinks —
 * purely client-side, mirrors url-preview.js's own "rough approximation
 * for display only" contract (the server's PermalinkService remains the
 * actual authority on what a real post's URL looks like).
 *
 * Markup contract (see admin/views/settings/permalinks.php):
 *   <form data-lp-permalink-structure-form>
 *     <input type="radio" name="structure_preset" data-lp-permalink-preset-value="/post/%postname%/">
 *     <input type="radio" name="structure_preset" value="custom">
 *     <input data-lp-permalink-custom-input>
 *     <code data-lp-permalink-preview></code>
 *   </form>
 * Selecting a preset radio copies its structure into the custom field (so
 * the field always reflects what will actually be saved) and refreshes
 * the preview; typing in the custom field does the same.
 */
(function () {
    'use strict';

    var SAMPLE_VALUES = {
        '%postname%': 'sample-post',
        '%year%': String(new Date().getFullYear()),
        '%monthnum%': String(new Date().getMonth() + 1).padStart(2, '0'),
        '%day%': String(new Date().getDate()).padStart(2, '0'),
        '%category%': 'sample-category',
        '%author%': 'sample-author',
    };

    function renderPreview(structure) {
        var path = String(structure || '');

        Object.keys(SAMPLE_VALUES).forEach(function (token) {
            path = path.split(token).join(SAMPLE_VALUES[token]);
        });

        return path;
    }

    function enhance(form) {
        var presetInputs = form.querySelectorAll('input[name="structure_preset"]');
        var customInput = form.querySelector('[data-lp-permalink-custom-input]');
        var preview = form.querySelector('[data-lp-permalink-preview]');

        if (!customInput || !preview) {
            return;
        }

        function update() {
            preview.textContent = renderPreview(customInput.value);
        }

        presetInputs.forEach(function (radio) {
            radio.addEventListener('change', function () {
                var presetValue = radio.getAttribute('data-lp-permalink-preset-value');

                if (presetValue) {
                    customInput.value = presetValue;
                }

                update();
            });
        });

        customInput.addEventListener('input', update);
    }

    document.querySelectorAll('[data-lp-permalink-structure-form]').forEach(enhance);
})();
