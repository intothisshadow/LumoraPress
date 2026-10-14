/**
 * Drives the Downloads list's "Check for Updates" (Downloads > All Downloads) a small batch at a
 * time, updating a progress bar in place instead of reloading for every batch.
 *
 * Markup contract: a <form data-lp-github-check-form data-lp-github-check-total="N"> with a hidden
 * `csrf_token`, followed by a sibling [data-lp-github-check-progress] block holding a
 * <progress data-lp-github-check-bar> and a [data-lp-github-check-status] element. The server
 * answers each POST that carries `X-Requested-With: fetch` with
 * { checked, remaining, stopped, done, changes, csrfToken }; `stopped` holds GitHub's own
 * explanation when it refused more requests (its hourly limit) or could not be reached.
 */
(function () {
    'use strict';

    var NEXT_BATCH_DELAY_MS = 200;

    function run(form) {
        var progress = form.parentNode.querySelector('[data-lp-github-check-progress]');
        var bar = progress ? progress.querySelector('[data-lp-github-check-bar]') : null;
        var status = progress ? progress.querySelector('[data-lp-github-check-status]') : null;
        var tokenField = form.querySelector('input[name="csrf_token"]');
        var button = form.querySelector('button[type="submit"]');
        var total = parseInt(form.getAttribute('data-lp-github-check-total') || '0', 10);
        var checked = 0;

        if (button) {
            button.disabled = true;
        }

        if (progress) {
            progress.hidden = false;
        }

        function show(text) {
            if (status) {
                status.textContent = text;
            }
        }

        function stop(message) {
            show(message);

            if (button) {
                button.disabled = false;
            }
        }

        function tick() {
            fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' },
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok) {
                            throw new Error(data.error || 'Unexpected response status: ' + response.status);
                        }

                        return data;
                    });
                })
                .then(function (data) {
                    checked += data.checked;

                    if (bar) {
                        bar.max = Math.max(total, 1);
                        bar.value = Math.min(total, Math.max(total - data.remaining, checked));
                    }

                    if (data.stopped) {
                        // Whatever was answered so far is kept; reload so its badges show.
                        stop('Stopped after ' + checked + ': ' + data.stopped + ' Reload the page to see the results so far.');

                        return;
                    }

                    if (data.done) {
                        show('Finished. ' + data.changes + ' with changes to review. Reloading…');
                        window.setTimeout(function () {
                            window.location.reload();
                        }, 600);

                        return;
                    }

                    show('Checked ' + checked + ', ' + data.remaining + ' to go.');

                    // Tokens are single-use, so each answer carries the next one.
                    if (data.csrfToken && tokenField) {
                        tokenField.value = data.csrfToken;
                    }

                    window.setTimeout(tick, NEXT_BATCH_DELAY_MS);
                })
                .catch(function (error) {
                    stop((error && error.message ? error.message : 'Something went wrong') + ' After ' + checked + ' checked. Reload the page to see the results so far.');
                });
        }

        tick();
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof fetch !== 'function') {
            return;
        }

        var form = document.querySelector('form[data-lp-github-check-form]');

        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                run(form);
            });
        }
    });
}());
