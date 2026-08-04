/**
 * Native HTML5 drag-and-drop reparenting for the Media Manager's Virtual
 * Folder System sidebar (LP-068): dragging a folder onto another folder
 * moves it there; dropping onto the "Folders" heading moves it back to
 * top level. Reuses each folder's existing per-folder rename form
 * (admin/views/media/media.php's $renderFolderTree) rather than a new
 * endpoint — the same `rename_folder` POST handler already accepts a
 * changed parent_id alongside the (unchanged) name, and a full page
 * reload is expected anyway since the tree's nesting changes. Cycle
 * prevention (dropping a folder onto its own descendant) is left to
 * FolderService::update(), which already throws and surfaces a generic
 * error banner on the reloaded page.
 *
 * Markup contract (see admin/views/media/media.php):
 *   <a data-lp-folder-drag data-folder-id="123">Name</a>
 *   <form data-lp-folder-move-form data-folder-id="123">
 *     <input name="parent_id" value="...">
 *     ...
 *   </form>
 *   <h2 data-lp-folder-root-drop>Folders</h2> — the "move to top level"
 *   drop target.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var links = document.querySelectorAll('[data-lp-folder-drag]');

        if (links.length === 0) {
            return;
        }

        function findMoveForm(folderId) {
            return document.querySelector('[data-lp-folder-move-form][data-folder-id="' + folderId + '"]');
        }

        function moveFolderTo(draggedId, newParentId) {
            if (!draggedId || draggedId === newParentId) {
                return;
            }

            var form = findMoveForm(draggedId);

            if (!form) {
                return;
            }

            var parentField = form.querySelector('input[name="parent_id"]');

            if (!parentField) {
                return;
            }

            parentField.value = newParentId;
            form.requestSubmit();
        }

        links.forEach(function (link) {
            link.addEventListener('dragstart', function (event) {
                event.dataTransfer.setData('text/plain', link.dataset.folderId);
                event.dataTransfer.effectAllowed = 'move';
            });

            link.addEventListener('dragover', function (event) {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                link.classList.add('is-drop-target');
            });

            link.addEventListener('dragleave', function () {
                link.classList.remove('is-drop-target');
            });

            link.addEventListener('drop', function (event) {
                event.preventDefault();
                link.classList.remove('is-drop-target');
                moveFolderTo(event.dataTransfer.getData('text/plain'), link.dataset.folderId);
            });
        });

        var rootDrop = document.querySelector('[data-lp-folder-root-drop]');

        if (rootDrop) {
            rootDrop.addEventListener('dragover', function (event) {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                rootDrop.classList.add('is-drop-target');
            });

            rootDrop.addEventListener('dragleave', function () {
                rootDrop.classList.remove('is-drop-target');
            });

            rootDrop.addEventListener('drop', function (event) {
                event.preventDefault();
                rootDrop.classList.remove('is-drop-target');
                moveFolderTo(event.dataTransfer.getData('text/plain'), '0');
            });
        }
    });
}());
