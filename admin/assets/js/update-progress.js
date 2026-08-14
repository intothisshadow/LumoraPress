/**
 * LP-086 Visible update progress: renders a live stage checklist (Backing
 * up files… Applying update files… Running database migrations…) for the
 * two long-running update actions on the Maintenance > Updates page —
 * "Confirm & Install" and GitHub's "Download & Install" — while the POST
 * driving each one is still in flight on another connection.
 *
 * The endpoint still returns a full re-rendered HTML page either way
 * (same as admin/assets/js/update-upload.js's manual-upload flow), so on
 * completion the response document simply replaces this one. The stage
 * list is purely a transient "here's what's happening right now" overlay
 * for the wait — it never has to persist past that point.
 *
 * Markup contract (see admin/views/maintenance/updates.php):
 *   <form data-lp-update-progress-form
 *         data-lp-update-progress-url="...?ajax=progress"
 *         data-lp-update-progress-target="some-id">
 *     ...
 *   </form>
 *   <ul id="some-id" class="lp-update-progress" hidden></ul>
 *
 * Polls UpdateProgress::read() (via the `?ajax=progress` URL) on an
 * interval while the form's own submission is in flight, rendering
 * whatever stage list it reports. See UpdateProgress's own docblock for
 * why this only works at all: the PHP request behind the form submission
 * calls session_write_close() before starting its long operation, so this
 * polling request (sharing the same session) isn't blocked behind it.
 */
(function () {
    'use strict';

    var POLL_INTERVAL_MS = 700;

    function renderStages(listEl, state) {
        listEl.innerHTML = '';

        if (!state || !state.stages || state.stages.length === 0) {
            listEl.hidden = true;

            return;
        }

        listEl.hidden = false;

        state.stages.forEach(function (stage) {
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

    function pollProgress(url, listEl) {
        var stopped = false;
        var timer = null;

        function tick() {
            fetch(url, { credentials: 'same-origin' })
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (state) {
                    if (stopped || !state) {
                        return;
                    }

                    renderStages(listEl, state);
                })
                .catch(function () {
                    // A transient network hiccup while polling isn't worth
                    // surfacing — the main form submission (tracked
                    // separately) is what ultimately reports success/failure.
                });
        }

        tick();
        timer = window.setInterval(tick, POLL_INTERVAL_MS);

        return function stop() {
            stopped = true;
            window.clearInterval(timer);
        };
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof fetch !== 'function') {
            return;
        }

        var forms = document.querySelectorAll('[data-lp-update-progress-form]');

        forms.forEach(function (form) {
            var progressUrl = form.dataset.lpUpdateProgressUrl;
            var targetId = form.dataset.lpUpdateProgressTarget;
            var listEl = targetId ? document.getElementById(targetId) : null;

            if (!progressUrl || !listEl) {
                return;
            }

            form.addEventListener('submit', function (event) {
                event.preventDefault();

                var formData = new FormData(form);
                var submitButton = form.querySelector('button[type="submit"]');

                if (submitButton) {
                    submitButton.disabled = true;
                }

                var stopPolling = pollProgress(progressUrl, listEl);

                fetch(form.getAttribute('action') || window.location.href, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                })
                    .then(function (response) {
                        stopPolling();

                        // install() redirects (302, followed automatically by
                        // fetch) on success; every other outcome — including
                        // a validation/blocking-problem re-render — returns
                        // the page directly. Either way, response.url is the
                        // final document to show.
                        if (response.redirected) {
                            window.location.href = response.url;

                            return null;
                        }

                        return response.text();
                    })
                    .then(function (html) {
                        if (html === null) {
                            return;
                        }

                        document.open();
                        document.write(html);
                        document.close();
                    })
                    .catch(function () {
                        stopPolling();

                        if (submitButton) {
                            submitButton.disabled = false;
                        }
                    });
            });
        });
    });
}());
