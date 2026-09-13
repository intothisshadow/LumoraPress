/**
 * Drives the Media Manager "Import from Server" screen's batch-import
 * loop (admin/views/media/import.php's continue_import branch) via
 * repeated fetch() calls instead of a full-page reload per batch — a
 * large directory scan can take dozens of batches, and reloading the
 * whole admin page that many times scrolls back to the top and visibly
 * flashes on every batch. Mirrors admin/assets/js/geoip-import-continue.js's
 * approach for the Visitor Stats GeoLite2 CSV import loop.
 *
 * Markup contract: a <form id="import-bulk-continue"> containing its own
 * submit button and a hidden `csrf_token` field, plus sibling
 * [data-lp-import-*] elements (inside the status line and progress bar
 * above the form) this script updates in place. Without JavaScript (or
 * if fetch() throws), the <form> itself is the real fallback: its submit
 * button still works, driving the exact same redirect-per-batch flow
 * this script replaces when it can run — see import.php's
 * $isAjaxContinueRequest check, which is what makes the server respond
 * with JSON only when this script's own fetch() call asks for it.
 */
(function () {
    'use strict';

    var RETRY_DELAY_MS = 400;

    function driveForm(form) {
        var importedEl = document.querySelector('[data-lp-import-imported]');
        var duplicateEl = document.querySelector('[data-lp-import-duplicate]');
        var failedEl = document.querySelector('[data-lp-import-failed]');
        var processedEl = document.querySelector('[data-lp-import-processed]');
        var totalEl = document.querySelector('[data-lp-import-total]');
        var progressBar = document.querySelector('[data-lp-import-progress-bar]');
        var progressBarWrap = document.querySelector('[data-lp-import-progress-bar-wrap]');
        var tokenField = form.querySelector('input[name="csrf_token"]');
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

                    if (importedEl && typeof data.imported === 'number') {
                        importedEl.textContent = data.imported.toLocaleString();
                    }

                    if (duplicateEl && typeof data.duplicate === 'number') {
                        duplicateEl.textContent = data.duplicate.toLocaleString();
                    }

                    if (failedEl && typeof data.failed === 'number') {
                        failedEl.textContent = data.failed.toLocaleString();
                    }

                    if (processedEl && typeof data.processed === 'number') {
                        processedEl.textContent = data.processed.toLocaleString();
                    }

                    if (totalEl && typeof data.total === 'number') {
                        totalEl.textContent = data.total.toLocaleString();
                    }

                    if (typeof data.percent === 'number') {
                        if (progressBar) {
                            progressBar.style.width = data.percent + '%';
                        }

                        if (progressBarWrap) {
                            progressBarWrap.setAttribute('aria-valuenow', String(data.percent));
                        }
                    }

                    // See update-continue.js's identical note — the CSRF
                    // token in the JSON response is single-use and must
                    // be written back into the form before the next
                    // tick() reads it via `new FormData(form)`.
                    if (data.csrf_token && tokenField) {
                        tokenField.value = data.csrf_token;
                    }

                    window.setTimeout(tick, RETRY_DELAY_MS);
                })
                .catch(function () {
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

        var form = document.getElementById('import-bulk-continue');

        if (form) {
            driveForm(form);
        }
    });
}());
