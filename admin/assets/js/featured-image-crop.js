/**
 * Manual featured-image cropping (LP-040): a small drag-to-select
 * rectangle over the current featured image, writing the selection back
 * into four hidden inputs (in the *original* image's pixel coordinates,
 * not the on-screen displayed size) that the save handler in
 * admin/views/posts/new.php / admin/views/pages.php reads directly. No
 * cropping library is loaded for this — a rectangle you can draw, move,
 * and resize by one corner is implementable in plain DOM/mouse-event code
 * without a new CDN dependency, matching this project's "no dependency
 * unless it earns its keep" posture (see docs/THIRD-PARTY.md).
 *
 * Cropping is only offered for an *already-saved* featured image (see the
 * PHP-side `$currentFeaturedImage !== null` guard around this markup) —
 * a freshly-chosen-but-not-yet-uploaded file has no server-side image to
 * draw a rectangle against yet, so save first, then reopen to crop.
 *
 * Markup contract (see the two admin views above):
 *   <div data-lp-featured-crop>
 *     <button data-lp-featured-crop-toggle>
 *     <div data-lp-featured-crop-editor hidden>
 *       <div data-lp-featured-crop-stage>
 *         <img data-lp-featured-crop-image>
 *         <div data-lp-featured-crop-rect hidden>
 *           <div data-lp-featured-crop-handle></div>
 *         </div>
 *       </div>
 *       <button data-lp-featured-crop-clear>
 *     </div>
 *     <input data-lp-featured-crop-x> <input data-lp-featured-crop-y>
 *     <input data-lp-featured-crop-width> <input data-lp-featured-crop-height>
 *   </div>
 */
(function () {
    'use strict';

    function enhance(wrapper) {
        var toggle = wrapper.querySelector('[data-lp-featured-crop-toggle]');
        var editor = wrapper.querySelector('[data-lp-featured-crop-editor]');
        var stage = wrapper.querySelector('[data-lp-featured-crop-stage]');
        var image = wrapper.querySelector('[data-lp-featured-crop-image]');
        var rect = wrapper.querySelector('[data-lp-featured-crop-rect]');
        var handle = wrapper.querySelector('[data-lp-featured-crop-handle]');
        var clearButton = wrapper.querySelector('[data-lp-featured-crop-clear]');
        var xInput = wrapper.querySelector('[data-lp-featured-crop-x]');
        var yInput = wrapper.querySelector('[data-lp-featured-crop-y]');
        var widthInput = wrapper.querySelector('[data-lp-featured-crop-width]');
        var heightInput = wrapper.querySelector('[data-lp-featured-crop-height]');

        if (!toggle || !editor || !stage || !image || !rect || !handle || !clearButton
            || !xInput || !yInput || !widthInput || !heightInput) {
            return;
        }

        var dragMode = null; // 'create' | 'move' | 'resize'
        var dragStartX = 0;
        var dragStartY = 0;
        var rectStart = { left: 0, top: 0, width: 0, height: 0 };

        function naturalToDisplayRatio() {
            return image.clientWidth > 0 ? image.naturalWidth / image.clientWidth : 1;
        }

        function setRectStyle(left, top, width, height) {
            rect.style.left = left + 'px';
            rect.style.top = top + 'px';
            rect.style.width = width + 'px';
            rect.style.height = height + 'px';
        }

        function writeInputsFromDisplayRect(left, top, width, height) {
            var ratio = naturalToDisplayRatio();
            xInput.value = Math.round(left * ratio);
            yInput.value = Math.round(top * ratio);
            widthInput.value = Math.round(width * ratio);
            heightInput.value = Math.round(height * ratio);
        }

        // Shows a rectangle the moment the editor opens, rather than a
        // blank image the admin has to know to click-and-drag on from
        // scratch — the existing saved crop if there is one, or else a
        // default box inset 10% from every edge (so its handle is
        // immediately visible and adjustable) rather than nothing at all.
        function showInitialRect() {
            var ratio = naturalToDisplayRatio();

            if (ratio === 0) {
                return;
            }

            if (xInput.value !== '' && widthInput.value !== '' && heightInput.value !== '') {
                setRectStyle(
                    parseInt(xInput.value, 10) / ratio,
                    parseInt(yInput.value, 10) / ratio,
                    parseInt(widthInput.value, 10) / ratio,
                    parseInt(heightInput.value, 10) / ratio,
                );
                rect.hidden = false;

                return;
            }

            var insetX = stage.clientWidth * 0.1;
            var insetY = stage.clientHeight * 0.1;
            var left = insetX;
            var top = insetY;
            var width = stage.clientWidth - insetX * 2;
            var height = stage.clientHeight - insetY * 2;

            setRectStyle(left, top, width, height);
            rect.hidden = false;
            writeInputsFromDisplayRect(left, top, width, height);
        }

        function stagePoint(event) {
            var bounds = stage.getBoundingClientRect();
            var point = (event.touches && event.touches[0]) || event;

            return {
                x: Math.max(0, Math.min(point.clientX - bounds.left, stage.clientWidth)),
                y: Math.max(0, Math.min(point.clientY - bounds.top, stage.clientHeight)),
            };
        }

        function currentRectBox() {
            return {
                left: parseFloat(rect.style.left) || 0,
                top: parseFloat(rect.style.top) || 0,
                width: parseFloat(rect.style.width) || 0,
                height: parseFloat(rect.style.height) || 0,
            };
        }

        function onPointerDown(event) {
            var point = stagePoint(event);

            if (event.target === handle) {
                dragMode = 'resize';
                rectStart = currentRectBox();
            } else if (event.target === rect && !rect.hidden) {
                dragMode = 'move';
                rectStart = currentRectBox();
            } else {
                dragMode = 'create';
                rectStart = { left: point.x, top: point.y, width: 0, height: 0 };
                setRectStyle(point.x, point.y, 0, 0);
                rect.hidden = false;
            }

            dragStartX = point.x;
            dragStartY = point.y;
            event.preventDefault();
        }

        function onPointerMove(event) {
            if (dragMode === null) {
                return;
            }

            var point = stagePoint(event);
            var deltaX = point.x - dragStartX;
            var deltaY = point.y - dragStartY;

            if (dragMode === 'create') {
                var left = Math.min(dragStartX, point.x);
                var top = Math.min(dragStartY, point.y);
                var width = Math.abs(point.x - dragStartX);
                var height = Math.abs(point.y - dragStartY);
                setRectStyle(left, top, width, height);
            } else if (dragMode === 'move') {
                var maxLeft = stage.clientWidth - rectStart.width;
                var maxTop = stage.clientHeight - rectStart.height;
                var newLeft = Math.max(0, Math.min(rectStart.left + deltaX, maxLeft));
                var newTop = Math.max(0, Math.min(rectStart.top + deltaY, maxTop));
                setRectStyle(newLeft, newTop, rectStart.width, rectStart.height);
            } else if (dragMode === 'resize') {
                var newWidth = Math.max(10, Math.min(rectStart.width + deltaX, stage.clientWidth - rectStart.left));
                var newHeight = Math.max(10, Math.min(rectStart.height + deltaY, stage.clientHeight - rectStart.top));
                setRectStyle(rectStart.left, rectStart.top, newWidth, newHeight);
            }

            event.preventDefault();
        }

        function onPointerUp() {
            if (dragMode === null) {
                return;
            }

            dragMode = null;
            var box = currentRectBox();

            if (box.width < 10 || box.height < 10) {
                rect.hidden = true;
                xInput.value = '';
                yInput.value = '';
                widthInput.value = '';
                heightInput.value = '';

                return;
            }

            writeInputsFromDisplayRect(box.left, box.top, box.width, box.height);
        }

        toggle.addEventListener('click', function () {
            editor.hidden = !editor.hidden;

            if (!editor.hidden) {
                if (image.complete) {
                    showInitialRect();
                } else {
                    image.addEventListener('load', showInitialRect, { once: true });
                }
            }
        });

        clearButton.addEventListener('click', function () {
            rect.hidden = true;
            xInput.value = '';
            yInput.value = '';
            widthInput.value = '';
            heightInput.value = '';
        });

        stage.addEventListener('mousedown', onPointerDown);
        stage.addEventListener('touchstart', onPointerDown, { passive: false });
        document.addEventListener('mousemove', onPointerMove);
        document.addEventListener('touchmove', onPointerMove, { passive: false });
        document.addEventListener('mouseup', onPointerUp);
        document.addEventListener('touchend', onPointerUp);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-lp-featured-crop]').forEach(enhance);
    });
}());
