/**
 * Persists the Media Manager sidebar's per-folder expand/collapse state
 * (LP-120) — native `<details>`/`<summary>` already handles the
 * expand/collapse interaction itself with zero JS; this only sends the
 * resulting collapsed-folder set to the server after each toggle, mirroring
 * sortable.js's AJAX-mode persistence pattern (one POST per change, the
 * response's fresh csrfToken swapped in since Csrf::verify() is
 * single-use).
 *
 * Markup contract (see admin/views/media/media.php's $renderFolderTree):
 *   <h2 data-lp-folder-root-drop
 *       data-lp-folder-tree-state-url="..."
 *       data-lp-folder-tree-state-csrf="...">Folders</h2>
 *   <details data-lp-folder-toggle data-folder-id="123" open>
 *     <summary>...</summary>
 *     <ul class="lp-folder-tree__children">...</ul>
 *   </details>
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-lp-folder-tree-state-url]');
        var toggles = document.querySelectorAll('[data-lp-folder-toggle]');

        if (!root || toggles.length === 0) {
            return;
        }

        function persist() {
            var url = root.dataset.lpFolderTreeStateUrl;
            var body = new URLSearchParams();
            body.set('form', 'save_folder_tree_state');
            body.set('csrf_token', root.dataset.lpFolderTreeStateCsrf || '');

            document.querySelectorAll('[data-lp-folder-toggle]:not([open])').forEach(function (details) {
                body.append('collapsed[]', details.dataset.folderId);
            });

            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (json) {
                    if (json && typeof json.csrfToken === 'string') {
                        root.dataset.lpFolderTreeStateCsrf = json.csrfToken;
                    }
                })
                .catch(function (error) {
                    // A UI-preference save failing isn't worth interrupting
                    // browsing for — worst case the tree just reverts to
                    // its previous saved shape next visit, while the
                    // expand/collapse the user just did still visually
                    // applied.
                    console.error('Failed to save the folder tree layout.', error);
                });
        }

        toggles.forEach(function (details) {
            details.addEventListener('toggle', persist);
        });
    });
}());
