/**
 * LP-026 Manual Update (ZIP Upload): drag-and-drop onto the upload box,
 * and a real upload-progress bar for the ZIP itself. LP-086 adds a second,
 * stage-checklist progress indicator (Validating package… Checking
 * compatibility…) covering what happens *after* the byte transfer
 * finishes but before the Update Summary page renders — extraction and
 * validation of a large archive can itself take a real moment, which the
 * byte-progress bar above has nothing left to show once it hits 100%.
 *
 * The endpoint returns a full re-rendered HTML page either way (the
 * Update Summary panel on success, or the same form with an error banner)
 * rather than JSON, so on completion the response document simply
 * replaces this one — the same outcome a normal form submission would
 * have produced, just with visible progress throughout the wait.
 *
 * Markup contract (see admin/views/maintenance/updates.php):
 *   <div data-lp-update-upload data-lp-update-progress-url="...?ajax=progress">
 *     <form enctype="multipart/form-data">
 *       <input type="file">
 *       <div data-lp-update-upload-progress hidden>
 *         <div data-lp-update-upload-progress-bar></div>
 *       </div>
 *       <ul data-lp-update-upload-stages hidden></ul>
 *       <button type="submit">...</button>
 *     </form>
 *   </div>
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var container = document.querySelector('[data-lp-update-upload]');

        if (!container) {
            return;
        }

        var form = container.querySelector('form');
        var fileInput = container.querySelector('input[type="file"]');
        var progressWrap = container.querySelector('[data-lp-update-upload-progress]');
        var progressBar = progressWrap ? progressWrap.querySelector('[data-lp-update-upload-progress-bar]') : null;
        var stagesList = container.querySelector('[data-lp-update-upload-stages]');
        var progressUrl = container.dataset.lpUpdateProgressUrl;
        var submitButton = form ? form.querySelector('button[type="submit"]') : null;

        if (!form || !fileInput) {
            return;
        }

        // Renders UpdateProgress::read()'s stage list — a small, self-
        // contained duplicate of update-progress.js's identical helper
        // rather than a shared module, since the two files' polling starts
        // from different triggers (xhr.upload completing here vs. the
        // form submit itself there) and have no other code in common.
        function renderStages(state) {
            if (!stagesList) {
                return;
            }

            stagesList.innerHTML = '';

            if (!state || !state.stages || state.stages.length === 0) {
                stagesList.hidden = true;

                return;
            }

            stagesList.hidden = false;

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
                stagesList.appendChild(item);
            });
        }

        function startStagePolling() {
            if (!stagesList || !progressUrl || typeof fetch !== 'function') {
                return function stop() {};
            }

            var stopped = false;
            var timer = window.setInterval(function () {
                fetch(progressUrl, { credentials: 'same-origin' })
                    .then(function (response) { return response.ok ? response.json() : null; })
                    .then(function (state) {
                        if (!stopped && state) {
                            renderStages(state);
                        }
                    })
                    .catch(function () {});
            }, 700);

            return function stop() {
                stopped = true;
                window.clearInterval(timer);
            };
        }

        ['dragenter', 'dragover'].forEach(function (eventName) {
            container.addEventListener(eventName, function (event) {
                event.preventDefault();
                event.stopPropagation();
                container.classList.add('is-drop-target');
            });
        });

        ['dragleave', 'drop'].forEach(function (eventName) {
            container.addEventListener(eventName, function (event) {
                event.preventDefault();
                event.stopPropagation();
                container.classList.remove('is-drop-target');
            });
        });

        container.addEventListener('drop', function (event) {
            var files = event.dataTransfer ? event.dataTransfer.files : null;

            if (files && files.length > 0) {
                fileInput.files = files;
            }
        });

        form.addEventListener('submit', function (event) {
            // Nothing chosen yet (or a browser that skipped our drop
            // handler) — let the browser's own "required" validation and
            // a normal submission handle it.
            if (!fileInput.files || fileInput.files.length === 0 || typeof XMLHttpRequest === 'undefined') {
                return;
            }

            event.preventDefault();

            var formData = new FormData(form);
            var xhr = new XMLHttpRequest();

            xhr.open('POST', form.getAttribute('action') || window.location.href, true);

            if (progressWrap) {
                progressWrap.hidden = false;
            }

            if (progressBar) {
                progressBar.style.width = '0%';
            }

            if (submitButton) {
                submitButton.disabled = true;
            }

            var stopStagePolling = function () {};

            xhr.upload.addEventListener('progress', function (progressEvent) {
                if (!progressEvent.lengthComputable || !progressBar) {
                    return;
                }

                var percent = Math.round((progressEvent.loaded / progressEvent.total) * 100);
                progressBar.style.width = percent + '%';
                progressWrap.setAttribute('aria-valuenow', String(percent));
            });

            // The byte transfer itself is done once this fires — the
            // server is now extracting/validating the archive, which is
            // what the stage checklist below covers. The upload's own
            // percentage bar stays at 100% throughout that.
            xhr.upload.addEventListener('loadend', function () {
                stopStagePolling = startStagePolling();
            });

            xhr.addEventListener('load', function () {
                stopStagePolling();
                document.open();
                document.write(xhr.responseText);
                document.close();
            });

            xhr.addEventListener('error', function () {
                stopStagePolling();

                if (submitButton) {
                    submitButton.disabled = false;
                }

                if (progressWrap) {
                    progressWrap.hidden = true;
                }
            });

            xhr.send(formData);
        });
    });
}());
