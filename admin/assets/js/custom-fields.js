/**
 * LP-008 "Custom fields" — progressively enhances the repeatable
 * key/value row editor with Add/Remove row buttons. Without JavaScript,
 * the rows rendered server-side (one per existing custom field, plus one
 * blank row) still submit correctly as parallel meta_keys[]/meta_values[]
 * arrays — this script only makes adding/removing rows nicer.
 *
 * Markup contract (see admin/views/posts/new.php):
 *   <div data-lp-custom-fields>
 *     <div data-lp-custom-fields-rows>
 *       <div class="lp-custom-fields__row">
 *         <input type="text" name="meta_keys[]">
 *         <input type="text" name="meta_values[]">
 *         <button type="button" data-lp-custom-fields-remove>Remove</button>
 *       </div>
 *       ...
 *     </div>
 *     <button type="button" data-lp-custom-fields-add>Add Custom Field</button>
 *   </div>
 */
(function () {
    'use strict';

    function enhance(wrapper) {
        var rows = wrapper.querySelector('[data-lp-custom-fields-rows]');
        var addButton = wrapper.querySelector('[data-lp-custom-fields-add]');

        if (!rows || !addButton) {
            return;
        }

        function bindRemove(row) {
            var removeButton = row.querySelector('[data-lp-custom-fields-remove]');

            if (removeButton) {
                removeButton.addEventListener('click', function () {
                    row.remove();
                });
            }
        }

        Array.prototype.forEach.call(rows.querySelectorAll('.lp-custom-fields__row'), bindRemove);

        addButton.addEventListener('click', function () {
            var row = document.createElement('div');
            row.className = 'lp-custom-fields__row';
            row.innerHTML =
                '<input type="text" name="meta_keys[]" placeholder="Field name">' +
                '<input type="text" name="meta_values[]" placeholder="Value">' +
                '<button type="button" class="lp-button lp-button--link" data-lp-custom-fields-remove>Remove</button>';
            rows.appendChild(row);
            bindRemove(row);
        });
    }

    document.querySelectorAll('[data-lp-custom-fields]').forEach(enhance);
})();
