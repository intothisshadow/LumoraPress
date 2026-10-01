/**
 * Builds the Maintenance > Export file in steps, updating a progress bar in
 * place. The first request sends the Export form; when uploaded files are
 * included the server answers with progress instead of a finished file, and
 * this script keeps asking for the next batch until it reports done. Mirrors
 * media-import-continue.js.
 *
 * Markup contract: the form has class lp-export-form; [data-lp-export-*]
 * elements hold the progress and error text. Without JavaScript (or if the
 * very first request fails) the form submits normally and the server builds
 * the whole file in one request.
 */
(function () {
    'use strict';

    var NEXT_BATCH_DELAY_MS = 150;

    function start(form) {
        var progress = document.querySelector('[data-lp-export-progress]');
        var status = document.querySelector('[data-lp-export-status]');
        var bar = document.querySelector('[data-lp-export-bar]');
        var barWrap = document.querySelector('[data-lp-export-bar-wrap]');
        var errorEl = document.querySelector('[data-lp-export-error]');
        var button = form.querySelector('button[type="submit"]');
        var formToken = form.querySelector('input[name="csrf_token"]');

        function fail(message, freshFormToken) {
            if (freshFormToken && formToken) {
                formToken.value = freshFormToken;
            }

            errorEl.textContent = message;
            errorEl.hidden = false;
            progress.hidden = true;
            button.disabled = false;
        }

        function show(data) {
            progress.hidden = false;
            status.textContent = data.total > 0
                ? 'Adding uploaded files: ' + data.processed.toLocaleString() + ' of ' + data.total.toLocaleString()
                : 'Building the export…';
            bar.style.width = data.percent + '%';
            barWrap.setAttribute('aria-valuenow', String(data.percent));
        }

        function post(body, isFirst) {
            return fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' },
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.error) {
                        fail(data.error, data.form_csrf_token);

                        return;
                    }

                    if (data.done) {
                        window.location.href = data.redirect;

                        return;
                    }

                    show(data);

                    var next = new FormData();
                    next.append('form', 'export_continue');
                    next.append('csrf_token', data.csrf_token);
                    window.setTimeout(function () { post(next, false); }, NEXT_BATCH_DELAY_MS);
                })
                .catch(function () {
                    if (isFirst) {
                        // Nothing started that a normal submit can't redo.
                        form.dataset.lpExportNative = '1';
                        form.submit();

                        return;
                    }

                    fail('The export was interrupted. Start it again.');
                });
        }

        errorEl.hidden = true;
        button.disabled = true;
        status.textContent = 'Building the export…';
        progress.hidden = false;
        post(new FormData(form), true);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('.lp-export-form');

        if (!form || typeof fetch !== 'function') {
            return;
        }

        form.addEventListener('submit', function (event) {
            if (form.dataset.lpExportNative === '1') {
                return;
            }

            event.preventDefault();
            start(form);
        });
    });
}());
