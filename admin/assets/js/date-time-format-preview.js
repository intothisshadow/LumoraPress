/**
 * Settings > General "Date & Time" preset dropdowns — reveals the free-text
 * PHP date() format field only when "Custom" is selected, mirroring
 * permalink-preview.js's preset/custom split so the submitted value always
 * comes from one unambiguous source per field.
 *
 * Markup contract (see admin/views/settings/general.php):
 *   <p data-lp-format-field>
 *     <select data-lp-format-preset-select>
 *       <option value="F j, Y">August 13, 2026</option>
 *       <option value="custom">Custom</option>
 *     </select>
 *     <input data-lp-format-custom-input>
 *   </p>
 */
(function () {
    'use strict';

    function enhance(field) {
        var select = field.querySelector('[data-lp-format-preset-select]');
        var customInput = field.querySelector('[data-lp-format-custom-input]');

        if (!select || !customInput) {
            return;
        }

        select.addEventListener('change', function () {
            customInput.hidden = select.value !== 'custom';

            if (!customInput.hidden) {
                customInput.focus();
            }
        });
    }

    document.querySelectorAll('[data-lp-format-field]').forEach(enhance);
})();
