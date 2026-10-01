/**
 * Runs Maintenance > Import > Lumora Press Import as a loop of short
 * requests, showing each stage and a detail line (e.g. "Importing posts:
 * 1,200 of 8,400") in place. The first request sends the form (and an
 * uploaded file); each following one asks the server to carry on, until it
 * reports done. Mirrors import-continue.js.
 *
 * Markup contract (see admin/views/maintenance/import.php):
 *   <form data-lp-native-import-form> ... the progress list/detail below ...
 *   <ul id="lp-native-import-progress" hidden></ul>
 *   <p data-lp-native-import-detail hidden></p>
 *   <div data-lp-native-import-resume data-continue-token="..."> with a
 *   [data-lp-native-import-resume-button], for an import left unfinished.
 *
 * Without JavaScript the form still imports everything in one request.
 */
(function () {
    'use strict';

    var NEXT_ROUND_DELAY_MS = 250;
    var BUSY_RETRY_DELAY_MS = 2000;

    function renderStages(listEl, stages) {
        listEl.innerHTML = '';

        if (!stages || stages.length === 0) {
            return;
        }

        listEl.hidden = false;

        stages.forEach(function (stage) {
            var item = document.createElement('li');
            item.className = 'lp-update-progress__item is-' + stage.status;

            var marker = document.createElement('span');
            marker.className = 'lp-update-progress__marker';
            marker.setAttribute('aria-hidden', 'true');
            marker.textContent = stage.status === 'done' ? '✓' : '';

            var label = document.createElement('span');
            label.textContent = stage.label;

            item.appendChild(marker);
            item.appendChild(label);
            listEl.appendChild(item);
        });
    }

    function setDetail(detailEl, text, isError) {
        detailEl.textContent = text;
        detailEl.hidden = text === '';
        detailEl.setAttribute('role', isError ? 'alert' : 'status');
    }

    function run(listEl, detailEl, firstBody, continueToken, onIdle, onFormToken) {
        var token = continueToken;

        function handle(data) {
            if (data.error) {
                if (data.form_csrf_token && onFormToken) {
                    onFormToken(data.form_csrf_token);
                }

                setDetail(detailEl, data.error, true);
                onIdle();

                return;
            }

            if (data.done) {
                window.location.href = data.redirect;

                return;
            }

            token = data.csrf_token || token;

            if (data.busy) {
                setDetail(detailEl, data.detail || '', false);
                window.setTimeout(next, BUSY_RETRY_DELAY_MS);

                return;
            }

            renderStages(listEl, data.stages);
            setDetail(detailEl, data.detail || '', false);
            window.setTimeout(next, NEXT_ROUND_DELAY_MS);
        }

        function send(body) {
            return fetch(window.location.pathname + window.location.search.replace(/[?&]lumora_imported=1/, ''), {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' },
            })
                .then(function (response) { return response.text(); })
                .then(function (text) {
                    var parsed = null;

                    try {
                        parsed = JSON.parse(text);
                    } catch (error) {
                        parsed = null;
                    }

                    if (parsed === null || typeof parsed !== 'object') {
                        throw new Error('Unexpected response');
                    }

                    handle(parsed);
                })
                .catch(function () {
                    setDetail(detailEl, 'The server did not answer in time. Everything imported so far is kept: reload this page, then choose Resume Import to carry on.', true);
                    onIdle();
                });
        }

        function next() {
            var body = new FormData();
            body.append('form', 'continue_lumora_press_import');
            body.append('csrf_token', token);
            send(body);
        }

        setDetail(detailEl, 'Starting…', false);

        if (firstBody) {
            send(firstBody);
        } else {
            next();
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof fetch !== 'function') {
            return;
        }

        var listEl = document.getElementById('lp-native-import-progress');
        var detailEl = document.querySelector('[data-lp-native-import-detail]');

        if (!listEl || !detailEl) {
            return;
        }

        var form = document.querySelector('[data-lp-native-import-form]');

        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();

                var button = form.querySelector('button[type="submit"]');
                var tokenField = form.querySelector('input[name="csrf_token"]');

                button.disabled = true;
                run(listEl, detailEl, new FormData(form), '', function () { button.disabled = false; }, function (fresh) {
                    tokenField.value = fresh;
                });
            });
        }

        var resume = document.querySelector('[data-lp-native-import-resume]');
        var resumeButton = resume ? resume.querySelector('[data-lp-native-import-resume-button]') : null;

        if (resume && resumeButton) {
            resumeButton.addEventListener('click', function () {
                resumeButton.disabled = true;
                run(listEl, detailEl, null, resume.dataset.continueToken || '', function () { resumeButton.disabled = false; }, null);
            });
        }
    });
}());
