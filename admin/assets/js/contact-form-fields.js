/**
 * LPP-003 Contact Forms — progressively enhances the repeatable field-row
 * editor with Add/Remove/Move Up/Move Down. Without JavaScript, the rows
 * rendered server-side (one per existing field, plus one blank row) still
 * submit correctly as parallel field_labels[]/field_types[]/
 * field_required[]/field_options[] arrays — this script only makes
 * adding/removing/reordering rows nicer, the same "server-rendered rows
 * always work, JS just improves the editing experience" contract
 * custom-fields.js already establishes for Custom Fields.
 *
 * Markup contract (see admin/views/contact-forms/add-new.php):
 *   <div data-lp-contact-form-fields>
 *     <div data-lp-contact-form-fields-rows>
 *       <div class="lp-contact-form-fields__row">
 *         <input type="text" name="field_labels[]">
 *         <select name="field_types[]">...</select>
 *         <select name="field_required[]">...</select>
 *         <input type="text" name="field_options[]">
 *         <button type="button" data-lp-contact-form-fields-move-up>Move Up</button>
 *         <button type="button" data-lp-contact-form-fields-move-down>Move Down</button>
 *         <button type="button" data-lp-contact-form-fields-remove>Remove</button>
 *       </div>
 *       ...
 *     </div>
 *     <button type="button" data-lp-contact-form-fields-add>Add Field</button>
 *   </div>
 *   <button type="button" data-lp-contact-form-fields-template>Use Contact Form Template</button>
 *
 * Move Up/Move Down swap the row's position in the DOM only — the whole
 * fields list is submitted together on Save, so no server round-trip is
 * needed per reorder (the "just up/down buttons, no drag-and-drop"
 * request this editor was built to satisfy).
 *
 * The template button (LPP-003 "Form templates" — Contact) is a
 * client-side-only convenience with no non-JS fallback, unlike every
 * other affordance here: add-new.php only renders it when adding a new
 * form (never while editing one), so there's no server-rendered state
 * it needs to degrade to — a visitor without JavaScript just doesn't see
 * the button and builds fields manually the way this editor already
 * supports.
 */
(function () {
    'use strict';

    function enhance(wrapper) {
        var rows = wrapper.querySelector('[data-lp-contact-form-fields-rows]');
        var addButton = wrapper.querySelector('[data-lp-contact-form-fields-add]');
        var templateButton = document.querySelector('[data-lp-contact-form-fields-template]');

        if (!rows || !addButton) {
            return;
        }

        function bindRow(row) {
            var removeButton = row.querySelector('[data-lp-contact-form-fields-remove]');
            var upButton = row.querySelector('[data-lp-contact-form-fields-move-up]');
            var downButton = row.querySelector('[data-lp-contact-form-fields-move-down]');

            if (removeButton) {
                removeButton.addEventListener('click', function () {
                    row.remove();
                });
            }

            if (upButton) {
                upButton.addEventListener('click', function () {
                    var previous = row.previousElementSibling;

                    if (previous) {
                        rows.insertBefore(row, previous);
                    }
                });
            }

            if (downButton) {
                downButton.addEventListener('click', function () {
                    var next = row.nextElementSibling;

                    if (next) {
                        rows.insertBefore(next, row);
                    }
                });
            }
        }

        Array.prototype.forEach.call(rows.querySelectorAll('.lp-contact-form-fields__row'), bindRow);

        addButton.addEventListener('click', function () {
            var existingRows = rows.querySelectorAll('.lp-contact-form-fields__row');
            var template = existingRows.length > 0 ? existingRows[existingRows.length - 1] : null;

            if (!template) {
                return;
            }

            var row = template.cloneNode(true);

            Array.prototype.forEach.call(row.querySelectorAll('input[type="text"]'), function (input) {
                input.value = '';
            });
            Array.prototype.forEach.call(row.querySelectorAll('select'), function (select) {
                select.selectedIndex = 0;
            });

            rows.appendChild(row);
            bindRow(row);
        });

        if (templateButton) {
            templateButton.addEventListener('click', function () {
                var blueprint = rows.querySelector('.lp-contact-form-fields__row');

                if (!blueprint) {
                    return;
                }

                // Order matches how a visitor actually reads and fills a
                // contact form top to bottom — what it's about, who's
                // asking, how to reach them, then the message itself.
                var templateFields = [
                    { type: 'subject', label: 'Subject' },
                    { type: 'name', label: 'Name' },
                    { type: 'email', label: 'Email' },
                    { type: 'message', label: 'Message' }
                ];

                rows.innerHTML = '';

                templateFields.forEach(function (field) {
                    var row = blueprint.cloneNode(true);
                    row.querySelector('input[name="field_labels[]"]').value = field.label;
                    row.querySelector('select[name="field_types[]"]').value = field.type;
                    row.querySelector('select[name="field_required[]"]').value = '1';
                    row.querySelector('input[name="field_options[]"]').value = '';

                    rows.appendChild(row);
                    bindRow(row);
                });

                var titleInput = document.getElementById('contact-form-title');

                if (titleInput && titleInput.value.trim() === '') {
                    titleInput.value = 'Contact Form';
                }
            });
        }
    }

    document.querySelectorAll('[data-lp-contact-form-fields]').forEach(enhance);
}());
