/**
 * Drives the Media Manager's bulk thumbnail regeneration batches
 * (admin/views/media/thumbnails.php) via repeated fetch() calls, updating
 * the progress text and bar in place instead of reloading the whole page
 * for every batch. Mirrors media-import-continue.js.
 *
 * Markup contract: a <form id="thumb-bulk-continue"> with a hidden
 * `csrf_token` and `offset` field and its own submit button, plus
 * [data-lp-thumb-*] elements in the progress notice above it. Without
 * JavaScript (or if fetch() fails) the form's own button still works: it
 * submits normally and the server redirects back with the next batch.
 */
(function () {
    'use strict';

    var NEXT_BATCH_DELAY_MS = 200;

    function driveForm(form) {
        var progressEl = document.querySelector('[data-lp-thumb-progress]');
        var totalEl = document.querySelector('[data-lp-thumb-total]');
        var bar = document.querySelector('[data-lp-thumb-bar]');
        var barWrap = document.querySelector('[data-lp-thumb-bar-wrap]');
        var doneEl = document.querySelector('[data-lp-thumb-done]');
        var tokenField = form.querySelector('input[name="csrf_token"]');
        var offsetField = form.querySelector('input[name="offset"]');
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
                    if (progressEl && typeof data.processed === 'number') {
                        progressEl.textContent = data.processed.toLocaleString();
                    }

                    if (totalEl && typeof data.total === 'number') {
                        totalEl.textContent = data.total.toLocaleString();
                    }

                    if (typeof data.percent === 'number') {
                        if (bar) {
                            bar.style.width = data.percent + '%';
                        }

                        if (barWrap) {
                            barWrap.setAttribute('aria-valuenow', String(data.percent));
                        }
                    }

                    if (data.done) {
                        form.hidden = true;

                        if (doneEl) {
                            doneEl.hidden = false;
                        }

                        return;
                    }

                    // The token is single-use and the offset moves on each batch.
                    if (data.csrf_token && tokenField) {
                        tokenField.value = data.csrf_token;
                    }

                    if (offsetField && typeof data.next_offset === 'number') {
                        offsetField.value = String(data.next_offset);
                    }

                    window.setTimeout(tick, NEXT_BATCH_DELAY_MS);
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

        var form = document.getElementById('thumb-bulk-continue');

        if (form) {
            driveForm(form);
        }
    });
}());
