/**
 * Media Manager image editor: rotate, flip and crop with a live preview.
 *
 * The preview is drawn on a canvas using the same order of operations the
 * server applies (flip, then rotate clockwise, then crop), so the crop
 * rectangle is always expressed in pixels of the *transformed* image, which
 * is exactly what ThumbnailService::editImage() expects. Plain DOM and
 * pointer events, no cropping library. The numeric crop fields are the
 * keyboard-accessible alternative to dragging.
 *
 * Markup contract: see the "Edit Image" block in admin/views/media/media.php.
 */
(function () {
    'use strict';

    // Keeps the preview canvas cheap to repaint for very large originals.
    var MAX_PREVIEW_PIXELS = 1600;
    var MIN_DISPLAY_SIZE = 10;

    function enhance(wrapper) {
        var toggle = wrapper.querySelector('[data-lp-image-editor-toggle]');
        var form = wrapper.querySelector('[data-lp-image-editor-form]');
        var stage = wrapper.querySelector('[data-lp-image-editor-stage]');
        var canvas = wrapper.querySelector('[data-lp-image-editor-canvas]');
        var rect = wrapper.querySelector('[data-lp-image-editor-rect]');
        var ratioSelect = wrapper.querySelector('[data-lp-image-editor-ratio]');
        var statusEl = wrapper.querySelector('[data-lp-image-editor-status]');
        var rotateInput = wrapper.querySelector('[data-lp-image-editor-rotate]');
        var flipHInput = wrapper.querySelector('[data-lp-image-editor-flip-h]');
        var flipVInput = wrapper.querySelector('[data-lp-image-editor-flip-v]');
        var cropInputs = {
            x: wrapper.querySelector('[data-lp-image-editor-x]'),
            y: wrapper.querySelector('[data-lp-image-editor-y]'),
            width: wrapper.querySelector('[data-lp-image-editor-width]'),
            height: wrapper.querySelector('[data-lp-image-editor-height]'),
        };

        if (!form || !stage || !canvas || !rect || !ratioSelect || !statusEl
            || !rotateInput || !flipHInput || !flipVInput
            || !cropInputs.x || !cropInputs.y || !cropInputs.width || !cropInputs.height) {
            return;
        }

        var source = null;
        var rotation = 0;
        var flipH = false;
        var flipV = false;
        var drag = null;

        function transformedSize() {
            var swaps = rotation === 90 || rotation === 270;

            return {
                width: swaps ? source.naturalHeight : source.naturalWidth,
                height: swaps ? source.naturalWidth : source.naturalHeight,
            };
        }

        // Transformed-image pixels per displayed canvas pixel.
        function pixelRatio() {
            return canvas.clientWidth > 0 ? transformedSize().width / canvas.clientWidth : 1;
        }

        function aspectRatio() {
            if (ratioSelect.value === 'original') {
                var size = transformedSize();

                return size.width / size.height;
            }

            var parts = ratioSelect.value.split(':');

            if (parts.length !== 2) {
                return null;
            }

            return parseFloat(parts[0]) / parseFloat(parts[1]);
        }

        function render() {
            var size = transformedSize();
            var scale = Math.min(1, MAX_PREVIEW_PIXELS / Math.max(size.width, size.height));
            var context = canvas.getContext('2d');

            canvas.width = Math.max(1, Math.round(size.width * scale));
            canvas.height = Math.max(1, Math.round(size.height * scale));

            context.save();
            context.translate(canvas.width / 2, canvas.height / 2);
            context.rotate(rotation * Math.PI / 180);
            context.scale(flipH ? -1 : 1, flipV ? -1 : 1);
            context.drawImage(
                source,
                -source.naturalWidth * scale / 2,
                -source.naturalHeight * scale / 2,
                source.naturalWidth * scale,
                source.naturalHeight * scale
            );
            context.restore();
        }

        function setRect(box) {
            rect.style.left = box.left + 'px';
            rect.style.top = box.top + 'px';
            rect.style.width = box.width + 'px';
            rect.style.height = box.height + 'px';
        }

        function currentBox() {
            return {
                left: parseFloat(rect.style.left) || 0,
                top: parseFloat(rect.style.top) || 0,
                width: parseFloat(rect.style.width) || 0,
                height: parseFloat(rect.style.height) || 0,
            };
        }

        function clearCrop() {
            rect.hidden = true;
            cropInputs.x.value = '';
            cropInputs.y.value = '';
            cropInputs.width.value = '';
            cropInputs.height.value = '';
        }

        function writeInputs(box) {
            var ratio = pixelRatio();
            var size = transformedSize();
            var x = Math.max(0, Math.round(box.left * ratio));
            var y = Math.max(0, Math.round(box.top * ratio));

            cropInputs.x.value = x;
            cropInputs.y.value = y;
            cropInputs.width.value = Math.max(1, Math.min(Math.round(box.width * ratio), size.width - x));
            cropInputs.height.value = Math.max(1, Math.min(Math.round(box.height * ratio), size.height - y));
        }

        function updateStatus() {
            var parts = ['Rotation ' + rotation + '°'];

            if (flipH) {
                parts.push('flipped horizontally');
            }

            if (flipV) {
                parts.push('flipped vertically');
            }

            if (cropInputs.width.value !== '' && cropInputs.height.value !== '') {
                parts.push('crop ' + cropInputs.width.value + ' × ' + cropInputs.height.value + ' px');
            } else {
                parts.push('no crop area');
            }

            statusEl.textContent = parts.join(', ') + '.';
        }

        function commit(box) {
            writeInputs(box);
            updateStatus();
        }

        // A box dragged from a fixed anchor point to the pointer, optionally
        // held to the chosen aspect ratio and kept inside the stage.
        function boxFromAnchor(anchorX, anchorY, pointX, pointY) {
            var ratio = aspectRatio();
            var directionX = pointX < anchorX ? -1 : 1;
            var directionY = pointY < anchorY ? -1 : 1;
            var width = Math.abs(pointX - anchorX);
            var height = Math.abs(pointY - anchorY);

            if (ratio) {
                var roomX = directionX > 0 ? stage.clientWidth - anchorX : anchorX;
                var roomY = directionY > 0 ? stage.clientHeight - anchorY : anchorY;

                if (width / ratio > height) {
                    height = width / ratio;
                } else {
                    width = height * ratio;
                }

                if (width > roomX) {
                    width = roomX;
                    height = width / ratio;
                }

                if (height > roomY) {
                    height = roomY;
                    width = height * ratio;
                }
            }

            return {
                left: directionX > 0 ? anchorX : anchorX - width,
                top: directionY > 0 ? anchorY : anchorY - height,
                width: width,
                height: height,
            };
        }

        function stagePoint(event) {
            var bounds = stage.getBoundingClientRect();

            return {
                x: Math.max(0, Math.min(event.clientX - bounds.left, stage.clientWidth)),
                y: Math.max(0, Math.min(event.clientY - bounds.top, stage.clientHeight)),
            };
        }

        function onPointerDown(event) {
            if (event.button !== undefined && event.button !== 0) {
                return;
            }

            var point = stagePoint(event);
            var box = currentBox();
            var handle = event.target.getAttribute ? event.target.getAttribute('data-handle') : null;

            if (handle && !rect.hidden) {
                // The corner opposite the dragged handle stays put.
                drag = {
                    mode: 'resize',
                    anchorX: handle.indexOf('w') !== -1 ? box.left + box.width : box.left,
                    anchorY: handle.indexOf('n') !== -1 ? box.top + box.height : box.top,
                };
            } else if (event.target === rect && !rect.hidden) {
                drag = { mode: 'move', startX: point.x, startY: point.y, box: box };
            } else {
                drag = { mode: 'create', anchorX: point.x, anchorY: point.y };
                setRect({ left: point.x, top: point.y, width: 0, height: 0 });
                rect.hidden = false;
            }

            stage.setPointerCapture(event.pointerId);
            event.preventDefault();
        }

        function onPointerMove(event) {
            if (drag === null) {
                return;
            }

            var point = stagePoint(event);

            if (drag.mode === 'move') {
                setRect({
                    left: Math.max(0, Math.min(drag.box.left + point.x - drag.startX, stage.clientWidth - drag.box.width)),
                    top: Math.max(0, Math.min(drag.box.top + point.y - drag.startY, stage.clientHeight - drag.box.height)),
                    width: drag.box.width,
                    height: drag.box.height,
                });
            } else {
                setRect(boxFromAnchor(drag.anchorX, drag.anchorY, point.x, point.y));
            }

            event.preventDefault();
        }

        function onPointerUp() {
            if (drag === null) {
                return;
            }

            drag = null;
            var box = currentBox();

            if (box.width < MIN_DISPLAY_SIZE || box.height < MIN_DISPLAY_SIZE) {
                clearCrop();
                updateStatus();

                return;
            }

            commit(box);
        }

        // Typed values move the rectangle; the server clamps anything out of range.
        function onNumberInput() {
            var values = {};

            for (var key in cropInputs) {
                values[key] = parseInt(cropInputs[key].value, 10);

                if (isNaN(values[key]) || values[key] < (key === 'x' || key === 'y' ? 0 : 1)) {
                    return;
                }
            }

            var ratio = pixelRatio();

            rect.hidden = false;
            setRect({
                left: Math.min(values.x / ratio, stage.clientWidth),
                top: Math.min(values.y / ratio, stage.clientHeight),
                width: Math.min(values.width / ratio, stage.clientWidth - values.x / ratio),
                height: Math.min(values.height / ratio, stage.clientHeight - values.y / ratio),
            });
            updateStatus();
        }

        // Refits an existing selection to a newly chosen aspect ratio,
        // keeping its top-left corner.
        function onRatioChange() {
            var ratio = aspectRatio();
            var box = currentBox();

            if (!ratio || rect.hidden) {
                return;
            }

            var width = Math.min(box.width, stage.clientWidth - box.left);
            var height = width / ratio;

            if (height > stage.clientHeight - box.top) {
                height = stage.clientHeight - box.top;
                width = height * ratio;
            }

            var next = { left: box.left, top: box.top, width: width, height: height };

            setRect(next);
            commit(next);
        }

        function syncControls() {
            rotateInput.value = String(rotation);
            flipHInput.value = flipH ? '1' : '0';
            flipVInput.value = flipV ? '1' : '0';

            wrapper.querySelectorAll('[data-lp-image-editor-action="flip-h"]').forEach(function (button) {
                button.setAttribute('aria-pressed', flipH ? 'true' : 'false');
            });
            wrapper.querySelectorAll('[data-lp-image-editor-action="flip-v"]').forEach(function (button) {
                button.setAttribute('aria-pressed', flipV ? 'true' : 'false');
            });
        }

        function applyAction(action) {
            if (action === 'rotate-left') {
                rotation = (rotation + 270) % 360;
            } else if (action === 'rotate-right') {
                rotation = (rotation + 90) % 360;
            } else if (action === 'flip-h') {
                flipH = !flipH;
            } else if (action === 'flip-v') {
                flipV = !flipV;
            } else if (action === 'reset') {
                rotation = 0;
                flipH = false;
                flipV = false;
            } else if (action === 'clear-crop') {
                clearCrop();
                updateStatus();

                return;
            } else {
                return;
            }

            // The selection was drawn against the previous orientation.
            clearCrop();
            syncControls();
            render();
            updateStatus();
        }

        function load(src) {
            if (source !== null) {
                return;
            }

            source = new Image();
            source.addEventListener('load', function () {
                render();
                syncControls();
                updateStatus();
            });
            source.addEventListener('error', function () {
                statusEl.textContent = 'The image could not be loaded for editing.';
            });
            source.src = src;
        }

        // The inline editor (Media Manager) starts collapsed behind a toggle
        // button; the dialog's copy has none and is always showing.
        if (toggle) {
            toggle.addEventListener('click', function () {
                if (form.hidden) {
                    form.hidden = false;
                    toggle.setAttribute('aria-expanded', 'true');
                    load(wrapper.dataset.src || '');
                } else {
                    form.hidden = true;
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });
        }

        wrapper.addEventListener('click', function (event) {
            var button = event.target.closest('[data-lp-image-editor-action]');

            if (button && source !== null && source.complete) {
                applyAction(button.getAttribute('data-lp-image-editor-action'));
            }
        });

        ratioSelect.addEventListener('change', onRatioChange);

        Object.keys(cropInputs).forEach(function (key) {
            cropInputs[key].addEventListener('input', onNumberInput);
        });

        stage.addEventListener('pointerdown', onPointerDown);
        stage.addEventListener('pointermove', onPointerMove);
        stage.addEventListener('pointerup', onPointerUp);
        stage.addEventListener('pointercancel', onPointerUp);

        return { form: form, load: load };
    }

    /**
     * Opens the editor in a dialog for the media item `id` and saves over
     * fetch, for the editors and image pickers on other admin screens.
     * onSaved receives {item, replaced}: item is the picker-shaped media
     * row (a new copy, or the original after a replace) and `replaced` says
     * which. Does nothing if this page has no editor template (the user
     * cannot upload).
     *
     * Pass `id` (and optionally `src`), or the `url` of an image already placed
     * in content; with a url, onSaved also gets `size` (which size of the media
     * item that address was) and `previousItem` (the item before the edit).
     *
     * @param {{id: number, src: string, url: string, onSaved: function(Object): void}} options
     */
    function openDialog(options) {
        var config = document.querySelector('[data-lp-image-editor-config]');
        var template = document.getElementById('lp-image-editor-template');

        if (!config || !template || !options || (!options.id && !options.url)) {
            return;
        }

        var dialog = document.createElement('dialog');
        dialog.className = 'lp-image-editor-dialog';
        dialog.setAttribute('aria-labelledby', 'lp-image-editor-dialog-title');

        var heading = document.createElement('h2');
        heading.id = 'lp-image-editor-dialog-title';
        heading.className = 'lp-image-editor-dialog__title';
        heading.textContent = 'Edit Image';

        dialog.appendChild(heading);
        dialog.appendChild(template.content.cloneNode(true));

        var wrapper = dialog.querySelector('[data-lp-image-editor]');
        var controller = enhance(wrapper);

        if (!controller) {
            return;
        }

        var errorEl = wrapper.querySelector('[data-lp-image-editor-error]');
        var busy = false;
        var mediaId = options.id || 0;
        var previousItem = null;
        var matchedSize = null;

        function showError(message) {
            errorEl.textContent = message;
            errorEl.hidden = false;
        }

        // Single-use tokens: every response hands back the next one.
        function send(formData, retried) {
            formData.set('csrf_token', config.dataset.csrf);

            return fetch(config.dataset.url, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'fetch' },
            }).then(function (response) {
                return response.json().then(function (json) {
                    if (json.csrfToken) {
                        config.dataset.csrf = json.csrfToken;
                    }

                    // Another tab may have used the token; the response
                    // carries a fresh one, so one retry is safe.
                    if (response.status === 403 && !retried && json.csrfToken) {
                        return send(formData, true);
                    }

                    return { ok: response.ok, json: json };
                });
            });
        }

        controller.form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (busy) {
                return;
            }

            var formData = new FormData(controller.form);
            formData.set('form', 'edit_image_ajax');
            formData.set('id', String(mediaId));

            if (event.submitter && event.submitter.name) {
                formData.set(event.submitter.name, event.submitter.value);
            }

            busy = true;
            errorEl.hidden = true;
            wrapper.classList.add('is-busy');

            send(formData, false).then(function (result) {
                if (!result.ok || !result.json.item) {
                    showError(result.json.error || 'The image could not be edited.');

                    return;
                }

                dialog.close();

                if (options.onSaved) {
                    options.onSaved({ item: result.json.item, replaced: !!result.json.replaced, previousItem: previousItem, size: matchedSize });
                }
            }).catch(function () {
                showError('The image could not be edited. Check your connection and try again.');
            }).then(function () {
                busy = false;
                wrapper.classList.remove('is-busy');
            });
        });

        wrapper.querySelector('[data-lp-image-editor-cancel]').addEventListener('click', function () {
            dialog.close();
        });
        dialog.addEventListener('close', function () { dialog.remove(); });
        document.body.appendChild(dialog);
        dialog.showModal();

        if (options.src) {
            controller.load(options.src);

            return;
        }

        // Opened with just an id or a content address: look the image up first.
        var lookup = new FormData();
        lookup.set('form', 'edit_image_ajax');

        if (options.url) {
            lookup.set('lookup_url', options.url);
        } else {
            lookup.set('id', String(mediaId));
            lookup.set('lookup', '1');
        }

        send(lookup, false).then(function (result) {
            if (!result.ok || !result.json.item) {
                showError(result.json.error || 'The image could not be loaded for editing.');

                return;
            }

            mediaId = result.json.item.id;
            previousItem = result.json.item;
            matchedSize = result.json.size || null;
            controller.load(bust(result.json.item.url));
        }).catch(function () {
            showError('The image could not be loaded. Check your connection and try again.');
        });
    }

    // The same picture again at a new address, for previews that must not
    // show a cached copy after the file was replaced in place.
    function bust(url) {
        return url + (url.indexOf('?') === -1 ? '?' : '&') + 'v=' + Date.now();
    }

    window.lpImageEditor = { open: openDialog, bust: bust };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-lp-image-editor]').forEach(enhance);
    });
}());
