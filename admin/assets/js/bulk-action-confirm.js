/**
 * Generic "confirm only the destructive bulk action" enhancement (LP-163):
 * for a bulk-action bar whose dropdown mixes reversible options (Activate,
 * Deactivate) with a genuinely destructive one (Delete), only the
 * destructive option should prompt for confirmation — a static
 * data-lp-confirm on the Apply button would confirm every action alike.
 *
 * Markup contract:
 *   <select data-lp-bulk-confirm-select>
 *     <option value="activate">Activate</option>
 *     <option value="delete" data-lp-confirm="Delete permanently?">Delete</option>
 *   </select>
 *   <button data-lp-bulk-confirm-apply>Apply</button>
 * On change, the selected option's own data-lp-confirm (if any) is copied
 * onto the paired Apply button, so confirm-submit.js's existing click
 * handler (which reads button.dataset.lpConfirm at click time) confirms
 * only when a confirm-carrying option is selected.
 */
(function () {
    'use strict';

    function syncConfirm(select, button) {
        var option = select.options[select.selectedIndex];
        var confirmText = option ? option.dataset.lpConfirm : undefined;

        if (confirmText) {
            button.dataset.lpConfirm = confirmText;
        } else {
            delete button.dataset.lpConfirm;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-lp-bulk-confirm-select]').forEach(function (select) {
            var form = select.closest('form');

            if (!form) {
                return;
            }

            var button = form.querySelector('[data-lp-bulk-confirm-apply]');

            if (!button) {
                return;
            }

            syncConfirm(select, button);
            select.addEventListener('change', function () {
                syncConfirm(select, button);
            });
        });
    });
}());
