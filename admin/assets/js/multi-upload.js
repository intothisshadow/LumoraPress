/**
 * Media Manager multi-file upload (LP-080) — progressive enhancement over
 * the plain upload form: with JavaScript off, the form still submits
 * natively and uploads (per PHP's own handling of a same-named multi-file
 * input) whichever file the browser sends as `file`, exactly the single-
 * file behavior this form always had. With JavaScript on and more than
 * one file selected, this intercepts the submit and uploads each file in
 * its own sequential request against the same endpoint (no parallelism —
 * matches this codebase's existing "small batches, not aggressive
 * concurrency" house style, e.g. ThumbnailService's bulk-regenerate
 * batches), showing per-file queued/uploading/done/failed status.
 *
 * Each request passes `ajax=1`, which switches admin/views/media/
 * upload.php's handler to a JSON response instead of a redirect. CSRF
 * tokens are single-use (Csrf::verify() unsets the stored token on
 * success — app/Core/Security/Csrf.php) — every JSON response hands back
 * a *freshly generated* token for the 'upload' action, which this script
 * writes into the form's hidden csrf_token input before firing the next
 * file's request. Without this, only the first file in a multi-file
 * selection would ever succeed.
 *
 * Markup contract (see admin/views/media/upload.php):
 *   <form data-lp-multi-upload data-lp-multi-upload-media-url="...">
 *     <input type="hidden" name="csrf_token" value="...">
 *     <input type="file" name="file" multiple>
 *     <select name="folder_id">...</select>
 *     <button type="submit" data-lp-multi-upload-submit>
 *     <ul data-lp-multi-upload-list hidden></ul>
 *   </form>
 */
(function () {
    'use strict';

    function uploadOne(form, csrfInput, folderSelect, file) {
        var formData = new FormData();
        formData.append('form', 'upload');
        formData.append('csrf_token', csrfInput.value);
        formData.append('ajax', '1');
        formData.append('folder_id', folderSelect ? folderSelect.value : '0');
        formData.append('file', file);

        return fetch(form.action, { method: 'POST', body: formData }).then(function (response) {
            return response.json().then(function (json) {
                return { ok: response.ok, json: json };
            });
        });
    }

    function enhance(form) {
        var fileInput = form.querySelector('input[type="file"]');
        var csrfInput = form.querySelector('input[name="csrf_token"]');
        var folderSelect = form.querySelector('select[name="folder_id"]');
        var submitButton = form.querySelector('[data-lp-multi-upload-submit]');
        var list = form.querySelector('[data-lp-multi-upload-list]');
        var mediaUrl = form.getAttribute('data-lp-multi-upload-media-url') || '';

        if (!fileInput || !csrfInput || !submitButton || !list) {
            return;
        }

        form.addEventListener('submit', function (event) {
            var files = fileInput.files;

            if (!files || files.length < 2) {
                // A single file (or none, letting the browser's own
                // "required" validation handle it) submits the form
                // natively — no benefit to the AJAX path for one file,
                // and it keeps the classic "redirect to the new item's
                // edit screen" behavior exactly as it always was.
                return;
            }

            event.preventDefault();
            submitButton.disabled = true;
            list.hidden = false;
            list.innerHTML = '';

            var items = [];

            Array.prototype.forEach.call(files, function (file) {
                var item = document.createElement('li');
                item.textContent = file.name + ': queued';
                list.appendChild(item);
                items.push(item);
            });

            var index = 0;
            var successCount = 0;
            var failureCount = 0;

            function finish() {
                submitButton.disabled = false;

                var summary = document.createElement('li');
                summary.className = 'lp-multi-upload__summary';
                summary.textContent = successCount + ' of ' + files.length + ' uploaded'
                    + (failureCount > 0 ? ', ' + failureCount + ' failed' : '') + '.';
                list.appendChild(summary);

                if (mediaUrl !== '') {
                    var link = document.createElement('a');
                    link.href = mediaUrl;
                    link.className = 'lp-button';
                    link.textContent = 'Go to Media Library';
                    var linkItem = document.createElement('li');
                    linkItem.appendChild(link);
                    list.appendChild(linkItem);
                }
            }

            function next() {
                if (index >= files.length) {
                    finish();

                    return;
                }

                var file = files[index];
                var item = items[index];
                item.textContent = file.name + ': uploading…';

                uploadOne(form, csrfInput, folderSelect, file).then(function (result) {
                    if (result.json && result.json.csrfToken) {
                        csrfInput.value = result.json.csrfToken;
                    }

                    if (result.ok) {
                        item.textContent = file.name + ': done';
                        successCount++;
                    } else {
                        item.textContent = file.name + ': failed — ' + ((result.json && result.json.error) || 'Unknown error');
                        failureCount++;
                    }

                    index++;
                    next();
                }).catch(function () {
                    item.textContent = file.name + ': failed — network error';
                    failureCount++;
                    index++;
                    next();
                });
            }

            next();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-lp-multi-upload]').forEach(enhance);
    });
}());
