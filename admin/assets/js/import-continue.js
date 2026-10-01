/**
 * Drives the WordPress Importer's Import / Resume Import forms on Maintenance >
 * Import as a loop of short requests instead of one long one.
 *
 * Each POST runs the import for a short time budget and answers with JSON: the
 * stage list, a detail line (e.g. "Importing media: 1,200 of 8,400"), and a fresh
 * single-use CSRF token. This script shows that and posts again until the server
 * reports it is done, so no single request depends on the host's request timeout.
 *
 * Markup contract (see admin/views/maintenance/import.php):
 *   <form data-lp-import-form data-lp-import-target="some-id"> ... </form>
 *   <ul id="some-id" class="lp-update-progress" hidden></ul>
 *   <p data-lp-import-detail hidden></p>   (sibling of the list)
 *
 * Test Connection and a dry run are left to submit normally, as is anything without fetch(): the server
 * then runs one slice and the page offers Resume Import for the rest.
 */
(function () {
    'use strict';

    var NEXT_ROUND_DELAY_MS = 250;
    var BUSY_RETRY_DELAY_MS = 2000;

    function renderStages(listEl, stages) {
        listEl.innerHTML = '';

        if (!stages || stages.length === 0) {
            listEl.hidden = true;

            return;
        }

        listEl.hidden = false;

        stages.forEach(function (stage) {
            var item = document.createElement('li');
            item.className = 'lp-update-progress__item is-' + stage.status;

            var marker = document.createElement('span');
            marker.className = 'lp-update-progress__marker';
            marker.setAttribute('aria-hidden', 'true');
            marker.textContent = stage.status === 'done' ? '✓' : (stage.status === 'error' ? '✕' : '');

            var label = document.createElement('span');
            label.textContent = stage.label;

            item.appendChild(marker);
            item.appendChild(label);
            listEl.appendChild(item);
        });
    }

    function setDetail(detailEl, text, isError) {
        if (!detailEl) {
            return;
        }

        detailEl.textContent = text;
        detailEl.hidden = text === '';
        detailEl.setAttribute('role', isError ? 'alert' : 'status');
    }

    function drive(form) {
        var listEl = document.getElementById(form.dataset.lpImportTarget || '');
        var detailEl = listEl && listEl.parentElement ? listEl.parentElement.querySelector('[data-lp-import-detail]') : null;
        var submitButton = form.querySelector('button[type="submit"]');

        if (!listEl) {
            return;
        }

        // The clicked button's name/value isn't part of FormData(form), and the form
        // has two submit buttons, so the handler to run is set explicitly.
        function formData() {
            var data = new FormData(form);

            data.set('form', 'start_wordpress_import');

            return data;
        }

        function stop(message) {
            setDetail(detailEl, message, true);

            if (submitButton) {
                submitButton.disabled = false;
            }
        }

        function round() {
            fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: formData(),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' },
            })
                .then(function (response) {
                    // Decided by the body, not the Content-Type header: some hosts
                    // and proxies rewrite that header, and a JSON reply labelled
                    // text/html would otherwise be printed as a page.
                    return response.text().then(function (text) {
                        var parsed = null;

                        try {
                            parsed = JSON.parse(text);
                        } catch (error) {
                            parsed = null;
                        }

                        if (parsed !== null && typeof parsed === 'object' && 'done' in parsed) {
                            return parsed;
                        }

                        // A validation error re-renders the whole page, and a
                        // timeout from a proxy returns its own error page; show
                        // either rather than leaving the form looking stuck.
                        if (response.ok) {
                            document.open();
                            document.write(text);
                            document.close();

                            return null;
                        }

                        throw new Error('Unexpected response status: ' + response.status);
                    });
                })
                .then(function (data) {
                    if (data === null) {
                        return;
                    }

                    // The token is single-use, so it must be swapped in before
                    // the next round reads the form — or the next request fails
                    // verification and comes back as a full page instead of JSON.
                    var tokenField = form.querySelector('input[name="csrf_token"]');

                    if (data.csrf_token && tokenField) {
                        tokenField.value = data.csrf_token;
                    }

                    if (data.done) {
                        window.location.href = data.redirect;

                        return;
                    }

                    if (data.error) {
                        stop(data.error);

                        return;
                    }

                    // Another request holds the import; try again shortly.
                    if (data.busy) {
                        setDetail(detailEl, data.detail || '', false);
                        window.setTimeout(round, BUSY_RETRY_DELAY_MS);

                        return;
                    }

                    renderStages(listEl, data.stages);
                    setDetail(detailEl, data.detail || '', false);
                    window.setTimeout(round, NEXT_ROUND_DELAY_MS);
                })
                .catch(function () {
                    // Everything finished so far is saved, so reloading offers
                    // Resume Import for the same batch.
                    stop('The server did not answer in time. Reload this page, then choose Resume Import to carry on from where it stopped.');
                });
        }

        if (submitButton) {
            submitButton.disabled = true;
        }

        setDetail(detailEl, 'Starting…', false);
        round();
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof fetch !== 'function') {
            return;
        }

        document.querySelectorAll('[data-lp-import-form]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                // Test Connection and a dry run are ordinary page submissions.
                if (event.submitter && event.submitter.value === 'test_wordpress_connection') {
                    return;
                }

                if (form.querySelector('input[name="dry_run"]:checked')) {
                    return;
                }

                event.preventDefault();
                drive(form);
            });
        });
    });
}());
