/**
 * Quick Edit (LP-009): swaps a Pages list row for an inline form, saves
 * via the same-page `quick_edit` JSON sub-action (see
 * admin/views/pages/all-pages.php), and updates the row in place — no
 * navigation to the full editor, no full page reload. Flat/paginated
 * list view only — the drag-and-drop tree view (the unfiltered "All"
 * tab) doesn't get Quick Edit this pass, since a parent/status change
 * there could move where a page belongs in the tree in a way this
 * row's own DOM can't reproduce.
 *
 * Markup contract (see admin/views/pages/all-pages.php):
 *   <tr id="page-row-{id}">...
 *     <a data-lp-quick-edit-title-link>...
 *     <span data-lp-quick-edit-status-badge>...
 *     <button data-lp-quick-edit-trigger
 *             data-lp-quick-edit-show="page-quick-edit-{id}"
 *             data-lp-quick-edit-hide="page-row-{id}">
 *   <tr id="page-quick-edit-{id}" hidden>
 *     <form data-lp-quick-edit-form data-lp-quick-edit-url="..."
 *           data-lp-quick-edit-show="page-row-{id}"
 *           data-lp-quick-edit-hide="page-quick-edit-{id}">
 *       ...fields...
 *       <p data-lp-quick-edit-error hidden>
 *       <button type="button" data-lp-quick-edit-cancel
 *               data-lp-quick-edit-show="page-row-{id}"
 *               data-lp-quick-edit-hide="page-quick-edit-{id}">
 *
 * A trigger/cancel button's own data-lp-quick-edit-show/-hide pair is
 * generic (toggle element A off, element B on) rather than Pages-
 * specific, so this same file could back a future Quick Edit on Posts
 * without changes.
 */
(function () {
    'use strict';

    function toggle(showId, hideId) {
        var showEl = document.getElementById(showId || '');
        var hideEl = document.getElementById(hideId || '');

        if (hideEl) {
            hideEl.hidden = true;
        }

        if (showEl) {
            showEl.hidden = false;
        }
    }

    function submitForm(form) {
        var errorEl = form.querySelector('[data-lp-quick-edit-error]');
        var submitButton = form.querySelector('button[type="submit"]');

        if (errorEl) {
            errorEl.hidden = true;
            errorEl.textContent = '';
        }

        if (submitButton) {
            submitButton.disabled = true;
        }

        fetch(form.dataset.lpQuickEditUrl, { method: 'POST', body: new FormData(form) })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (json.error) {
                    throw new Error(json.error);
                }

                var row = document.getElementById(form.dataset.lpQuickEditShow);
                var titleLink = row ? row.querySelector('[data-lp-quick-edit-title-link]') : null;
                var statusBadge = row ? row.querySelector('[data-lp-quick-edit-status-badge]') : null;

                if (titleLink) {
                    titleLink.textContent = json.data.title;
                }

                if (statusBadge) {
                    statusBadge.textContent = json.data.statusLabel;
                    statusBadge.className = 'lp-status-badge lp-status-badge--' + json.data.status;
                }

                toggle(form.dataset.lpQuickEditShow, form.dataset.lpQuickEditHide);
            })
            .catch(function (error) {
                if (errorEl) {
                    errorEl.textContent = error.message || 'Could not save that page.';
                    errorEl.hidden = false;
                }
            })
            .finally(function () {
                if (submitButton) {
                    submitButton.disabled = false;
                }
            });
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-lp-quick-edit-trigger], [data-lp-quick-edit-cancel]');

        if (!trigger) {
            return;
        }

        event.preventDefault();
        toggle(trigger.dataset.lpQuickEditShow, trigger.dataset.lpQuickEditHide);
    });

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-lp-quick-edit-form]');

        if (!form) {
            return;
        }

        event.preventDefault();
        submitForm(form);
    });
}());
