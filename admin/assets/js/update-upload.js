/**
 * LP-026 Manual Update (ZIP Upload): drag-and-drop onto the upload box,
 * and a real upload-progress bar for the ZIP itself. The server-rendered
 * "step-by-step status" (Update Summary / install progress) already
 * covers everything after the upload finishes — this only covers getting
 * the (often tens-of-MB) archive to the server in the first place, which
 * a plain synchronous form POST gives no feedback for at all.
 *
 * The endpoint returns a full re-rendered HTML page either way (the
 * Update Summary panel on success, or the same form with an error banner)
 * rather than JSON, so on completion the response document simply
 * replaces this one — the same outcome a normal form submission would
 * have produced, just with a progress bar during the upload itself.
 *
 * Markup contract (see admin/views/maintenance/updates.php):
 *   <div data-lp-update-upload>
 *     <form enctype="multipart/form-data">
 *       <input type="file">
 *       <div data-lp-update-upload-progress hidden>
 *         <div data-lp-update-upload-progress-bar></div>
 *       </div>
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
        var submitButton = form ? form.querySelector('button[type="submit"]') : null;

        if (!form || !fileInput) {
            return;
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

            xhr.upload.addEventListener('progress', function (progressEvent) {
                if (!progressEvent.lengthComputable || !progressBar) {
                    return;
                }

                var percent = Math.round((progressEvent.loaded / progressEvent.total) * 100);
                progressBar.style.width = percent + '%';
                progressWrap.setAttribute('aria-valuenow', String(percent));
            });

            xhr.addEventListener('load', function () {
                document.open();
                document.write(xhr.responseText);
                document.close();
            });

            xhr.addEventListener('error', function () {
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
