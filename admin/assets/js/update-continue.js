/**
 * Drives the Updates page's staged install/backup continue-loop
 * (admin/views/maintenance/updates.php's continue_install/
 * continue_backup_now branches) via repeated fetch() calls instead of a
 * full-page reload per batch — the database-backup stage in particular
 * can take dozens of batches on a large site (see UpdateBackupService::
 * backupDatabaseBatch()'s docblock), and reloading the whole page that
 * many times gives no way to tell "still working" from "stuck".
 *
 * Markup contract: a <form id="update-install-continue"> or
 * <form id="update-backup-continue">, each containing its own submit
 * button and a hidden `token` field, plus two sibling elements inside
 * the same panel — [data-lp-update-stage] and [data-lp-update-detail]
 * — this script writes the current stage label and a batch-progress
 * detail line into. Without JavaScript (or if fetch() throws), the
 * <form> itself is the real fallback: its submit button still works,
 * driving the exact same redirect-per-batch flow this script replaces
 * when it can run — see updates.php's $isAjaxContinueRequest check,
 * which is what makes the server respond with JSON only when this
 * script's own fetch() call asks for it.
 */
(function () {
    'use strict';

    var CONTINUE_FORM_IDS = ['update-install-continue', 'update-backup-continue'];
    var RETRY_DELAY_MS = 400;

    function describeDatabaseProgress(progress) {
        if (!progress) {
            return '';
        }

        var tableNumber = progress.table_index + 1;
        var rows = progress.row_offset;

        return 'Table ' + tableNumber + ' — ' + rows.toLocaleString() + ' row' + (rows === 1 ? '' : 's') + ' read so far…';
    }

    function driveForm(form) {
        var stageEl = form.parentElement ? form.parentElement.querySelector('[data-lp-update-stage]') : null;
        var detailEl = form.parentElement ? form.parentElement.querySelector('[data-lp-update-detail]') : null;
        var submitButton = form.querySelector('button[type="submit"]');

        if (submitButton) {
            submitButton.hidden = true;
        }

        function tick() {
            fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' },
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Unexpected response status: ' + response.status);
                    }

                    return response.json();
                })
                .then(function (data) {
                    if (data.done) {
                        window.location.href = data.redirect;

                        return;
                    }

                    if (stageEl && data.stage_label) {
                        stageEl.textContent = data.stage_label;
                    }

                    if (detailEl) {
                        var detail = describeDatabaseProgress(data.database_progress);
                        detailEl.textContent = detail;
                        detailEl.hidden = detail === '';
                    }

                    // The CSRF token in the JSON response is single-use
                    // and freshly generated server-side for this exact
                    // purpose — the form's own hidden field must be
                    // updated before the next tick() reads it via
                    // `new FormData(form)`, or that request fails
                    // verification and falls through to a full HTML
                    // re-render instead of JSON.
                    if (data.csrf_token) {
                        var tokenField = form.querySelector('input[name="csrf_token"]');

                        if (tokenField) {
                            tokenField.value = data.csrf_token;
                        }
                    }

                    window.setTimeout(tick, RETRY_DELAY_MS);
                })
                .catch(function () {
                    // A transient network hiccup, or fetch()/JSON isn't
                    // available for some reason — fall back to a real
                    // submit so the server's own redirect-per-batch
                    // fallback recovers the run instead of leaving the
                    // page stuck silently.
                    if (submitButton) {
                        submitButton.hidden = false;
                    }

                    form.submit();
                });
        }

        tick();
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof fetch !== 'function') {
            return;
        }

        CONTINUE_FORM_IDS.forEach(function (id) {
            var form = document.getElementById(id);

            if (form) {
                driveForm(form);
            }
        });
    });
}());
