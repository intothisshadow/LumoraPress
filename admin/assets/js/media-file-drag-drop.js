/**
 * Drag files from the Media Manager's Thumbnails or List view onto a sidebar
 * folder (including General Uploads and the Pinned group) to move them.
 *
 * A drag that starts on a checked item moves the whole checked selection; a
 * drag that starts on an unchecked item moves only that item. The drop is sent
 * through the same bulk "Move selected to..." handler the dropdown uses (a
 * normal form post with the form's own CSRF token), so the page reloads with
 * the usual flash message. The dropdown stays as the keyboard route.
 *
 * Files travel under their own data type so a file drag can never be mistaken
 * for folder reparenting (folder-drag-drop.js), and the other way round.
 *
 * Markup contract (see admin/views/media/media.php):
 *   [data-lp-media-drag][data-media-id]  a draggable file (grid card or list row)
 *   [data-lp-media-drop-target][data-folder-id]  a folder link; id 0 is General Uploads
 *   the bulk form: contains input[name="ids[]"], csrf_token, current_folder
 */
(function () {
    'use strict';

    var FILE_TYPE = 'application/x-lumora-media';
    var DRAGGING_CLASS = 'is-file-dragging';
    var HOVER_CLASS = 'is-file-drop-target';

    document.addEventListener('DOMContentLoaded', function () {
        var items = document.querySelectorAll('[data-lp-media-drag]');
        var targets = document.querySelectorAll('[data-lp-media-drop-target]');

        if (items.length === 0 || targets.length === 0) {
            return;
        }

        var sidebar = document.querySelector('.lp-media-manager__sidebar');
        var bulkForm = items[0].closest('form');
        var draggedIds = [];

        function hasFileData(event) {
            return Array.prototype.indexOf.call(event.dataTransfer.types || [], FILE_TYPE) !== -1;
        }

        function idsForDrag(item) {
            var checkbox = item.querySelector('input[name="ids[]"]');

            if (checkbox && checkbox.checked) {
                return Array.prototype.map.call(
                    document.querySelectorAll('input[name="ids[]"]:checked'),
                    function (input) { return input.value; }
                );
            }

            return [item.dataset.mediaId];
        }

        function dragPreview(count) {
            var preview = document.createElement('div');
            preview.className = 'lp-media-drag-preview';
            preview.textContent = 'Moving ' + count + (count === 1 ? ' file' : ' files');
            document.body.appendChild(preview);

            // The browser snapshots the element during dragstart, so it can go straight after.
            window.setTimeout(function () {
                preview.remove();
            }, 0);

            return preview;
        }

        function endDrag() {
            draggedIds = [];

            if (sidebar) {
                sidebar.classList.remove(DRAGGING_CLASS);
            }

            targets.forEach(function (target) {
                target.classList.remove(HOVER_CLASS);
            });
        }

        function submitMove(folderId) {
            if (!bulkForm || draggedIds.length === 0) {
                return;
            }

            var token = bulkForm.querySelector('input[name="csrf_token"]');
            var current = bulkForm.querySelector('input[name="current_folder"]');
            var form = document.createElement('form');
            form.method = 'post';
            form.action = bulkForm.getAttribute('action') || '';
            form.hidden = true;

            function addField(name, value) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            }

            addField('csrf_token', token ? token.value : '');
            addField('form', 'bulk_action');
            addField('bulk_action_type', 'move');
            addField('target_folder', folderId);
            addField('current_folder', current ? current.value : '');
            draggedIds.forEach(function (id) {
                addField('ids[]', id);
            });

            document.body.appendChild(form);
            form.submit();
        }

        items.forEach(function (item) {
            item.addEventListener('dragstart', function (event) {
                // Dragging the thumbnail or link inside the item starts a drag of that
                // element; this replaces its default data with the file selection.
                draggedIds = idsForDrag(item);
                event.dataTransfer.clearData();
                event.dataTransfer.setData(FILE_TYPE, draggedIds.join(','));
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setDragImage(dragPreview(draggedIds.length), 12, 12);

                if (sidebar) {
                    sidebar.classList.add(DRAGGING_CLASS);
                }
            });

            item.addEventListener('dragend', endDrag);
        });

        targets.forEach(function (target) {
            target.addEventListener('dragover', function (event) {
                if (!hasFileData(event)) {
                    return;
                }

                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                target.classList.add(HOVER_CLASS);
            });

            target.addEventListener('dragleave', function () {
                target.classList.remove(HOVER_CLASS);
            });

            target.addEventListener('drop', function (event) {
                if (!hasFileData(event)) {
                    return;
                }

                event.preventDefault();
                target.classList.remove(HOVER_CLASS);
                submitMove(target.dataset.folderId);
            });
        });
    });
}());
