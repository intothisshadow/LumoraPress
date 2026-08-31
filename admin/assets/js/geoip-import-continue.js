/**
 * Drives the Visitor Stats settings screen's GeoLite2 CSV batch-import
 * loop (admin/views/visitor-stats/settings.php's visitor_stats_geoip_
 * import_batch branch) via repeated fetch() calls instead of a full-page
 * reload per batch — the Blocks CSV alone can be ~450k rows, needing
 * dozens of batches, and reloading the whole admin page that many times
 * scrolls back to the top and visibly flashes on every batch. Mirrors
 * admin/assets/js/update-continue.js's approach for the Updates page's
 * own staged install/backup loop.
 *
 * Markup contract: a <form id="geoip-import-continue"> containing its own
 * submit button, hidden `byte_offset`/`is_first_batch`/`imported_so_far`
 * fields, and a hidden `csrf_token` field, plus a sibling
 * [data-lp-geoip-imported-so-far] element (inside the Status line above
 * the form) this script updates in place. Without JavaScript (or if
 * fetch() throws), the <form> itself is the real fallback: its submit
 * button still works, driving the exact same redirect-per-batch flow
 * this script replaces when it can run.
 *
 * Deliberately not handled by thumbnail-bulk.js's simpler auto-submit
 * loop (used for bulk thumbnail regen and server media import) — that
 * script's plain form.requestSubmit() is exactly the full-page-reload
 * behavior this one exists to avoid.
 */
(function () {
    'use strict';

    var RETRY_DELAY_MS = 400;

    function driveForm(form) {
        var importedEl = document.querySelector('[data-lp-geoip-imported-so-far]');
        var byteOffsetField = form.querySelector('input[name="byte_offset"]');
        var isFirstBatchField = form.querySelector('input[name="is_first_batch"]');
        var importedSoFarField = form.querySelector('input[name="imported_so_far"]');
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

                    if (importedEl && typeof data.imported_so_far === 'number') {
                        importedEl.textContent = data.imported_so_far.toLocaleString();
                    }

                    if (byteOffsetField && typeof data.byte_offset === 'number') {
                        byteOffsetField.value = String(data.byte_offset);
                    }

                    if (isFirstBatchField) {
                        isFirstBatchField.value = '0';
                    }

                    if (importedSoFarField && typeof data.imported_so_far === 'number') {
                        importedSoFarField.value = String(data.imported_so_far);
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

        var form = document.getElementById('geoip-import-continue');

        if (form) {
            driveForm(form);
        }
    });
}());
