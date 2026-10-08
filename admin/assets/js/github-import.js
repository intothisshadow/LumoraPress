/**
 * Drives Downloads > Import from GitHub (and a download's "Update from GitHub")
 * without reloading the page: it asks the server to begin a job, runs one
 * operation per request while showing progress, then finishes it. A failure
 * part way asks the server to cancel, which removes whatever the import had
 * already copied.
 *
 * Markup contract: a <form data-lp-github-import data-lp-github-mode="import|update"
 * data-lp-github-endpoint data-lp-github-csrf>, with a sibling
 * [data-lp-github-progress] holding [data-lp-github-bar], [data-lp-github-status]
 * and [data-lp-github-failures]. Every response carries a fresh single-use
 * CSRF token for the next call.
 */
(function () {
    'use strict';

    function init(form) {
        var endpoint = form.dataset.lpGithubEndpoint;
        var mode = form.dataset.lpGithubMode;
        var csrf = form.dataset.lpGithubCsrf;
        var container = form.parentElement;
        var progress = container.querySelector('[data-lp-github-progress]');
        var bar = container.querySelector('[data-lp-github-bar]');
        var status = container.querySelector('[data-lp-github-status]');
        var failures = container.querySelector('[data-lp-github-failures]');
        var submit = form.querySelector('button[type="submit"]');
        var jobToken = '';
        var running = false;

        function send(fields, useForm) {
            var body = useForm ? new FormData(form) : new FormData();

            body.set('form', 'github_import_run');
            body.set('csrf_token', csrf);

            Object.keys(fields).forEach(function (key) {
                body.set(key, fields[key]);
            });

            return fetch(endpoint, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' },
            }).then(function (response) {
                return response.json().then(function (json) {
                    if (json.csrfToken) {
                        csrf = json.csrfToken;
                    }

                    if (!response.ok) {
                        throw new Error(json.error || 'Something went wrong. Try again.');
                    }

                    return json;
                });
            });
        }

        function showStatus(message, isError) {
            status.textContent = message;
            status.classList.toggle('lp-field__hint--error', !!isError);
        }

        function stepAll(total) {
            function next() {
                return send({ phase: 'step', job: jobToken }).then(function (result) {
                    bar.max = Math.max(1, result.total);
                    bar.value = result.done;
                    showStatus(result.finished ? 'Saving the download…' : 'Copying screenshot ' + (result.done + 1) + ' of ' + result.total + '…');

                    return result.finished ? result : next();
                });
            }

            return total > 0 ? next() : Promise.resolve({ finished: true });
        }

        function fail(error) {
            showStatus(error.message, true);
            running = false;
            submit.disabled = false;

            if (jobToken === '') {
                return;
            }

            // The server removes anything an unfinished import had copied.
            send({ phase: 'cancel', job: jobToken }).catch(function () {}).then(function () {
                jobToken = '';
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (running) {
                return;
            }

            running = true;
            submit.disabled = true;
            progress.hidden = false;
            failures.textContent = '';
            bar.value = 0;
            showStatus('Reading GitHub…');

            send({ phase: mode === 'update' ? 'begin_update' : 'begin_import' }, true)
                .then(function (job) {
                    jobToken = job.token;
                    bar.max = Math.max(1, job.total);

                    return stepAll(job.total);
                })
                .then(function () {
                    return send({ phase: 'finish', job: jobToken });
                })
                .then(function (result) {
                    jobToken = '';

                    if (result.failed && result.failed.length > 0) {
                        showStatus('Saved, but ' + result.failed.length + ' screenshot' + (result.failed.length === 1 ? ' was' : 's were') + ' skipped:');
                        result.failed.forEach(function (message) {
                            var item = document.createElement('li');
                            item.textContent = message;
                            failures.appendChild(item);
                        });

                        var link = document.createElement('a');
                        link.className = 'lp-button lp-button--primary';
                        link.href = result.redirect;
                        link.textContent = 'Continue to the download';
                        progress.appendChild(link);

                        return;
                    }

                    showStatus('Done. Opening the download…');
                    window.location.href = result.redirect;
                })
                .catch(fail);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-lp-github-import]').forEach(init);
    });
}());
