/**
 * "Choose from Media Manager" featured-image picker — a WordPress-style
 * grid of image thumbnails, used wherever a featured image can be chosen
 * (the Post/Page editor sidebar's Featured Image box, and Media Manager's
 * Thumbnails screen "Default featured image" setting).
 *
 * Markup contract:
 *   <div data-lp-featured-image-picker
 *        data-picker-url="..."
 *        data-picker-csrf="..."
 *        data-media-folders='[{"id":1,"name":"...","depth":0}]'>
 *     <input type="hidden" name="featured_image_id" data-picker-value value="0">
 *     <button type="button" data-picker-trigger>Choose from Media Manager…</button>
 *     <span data-picker-chosen></span>
 *     <button type="button" data-picker-remove hidden>Remove</button>   (optional)
 *   </div>
 *
 * data-picker-remove is optional — the Post/Page editor's own Featured
 * Image box already has a separate "Remove current featured image"
 * checkbox (editor-featured-image.php) tied to existing server-side
 * remove_featured_image handling, so it omits this button. Media
 * Manager's "Default featured image" setting has no such checkbox (0
 * legitimately means "no default"), so it includes one.
 *
 * A deliberately separate, much lighter component than content-
 * editor.js's own openMediaPicker() ("Insert Image"): that one has a
 * whole Attachment Display Settings step (size/link/alignment) that
 * makes no sense for a featured image, which is always just "this
 * Media row's id" — clicking a grid item here selects it immediately,
 * no second step. Modeled on downloads-picker.js's identical shape.
 *
 * Queries its own `featured_image_picker_query` sub-action rather than
 * content-editor.js's `media_picker_query` — the two pickers can both
 * be open on the same editor page, and Csrf::token() overwrites the
 * single stored token per action name, so sharing one would let
 * opening either picker silently invalidate the other's already-
 * embedded token.
 */
(function () {
    'use strict';

    function gridThumbnailUrl(item) {
        var sizes = item.sizes || {};

        return (sizes.small || sizes.medium || sizes.large || sizes.full || { url: item.url }).url;
    }

    function openFeaturedImagePicker(container, onSelect) {
        var folders = JSON.parse(container.dataset.mediaFolders || '[]');
        var pickerUrl = container.dataset.pickerUrl;
        var pickerCsrf = container.dataset.pickerCsrf;

        var dialog = document.createElement('dialog');
        dialog.className = 'lp-featured-image-picker-dialog';

        var header = document.createElement('div');
        header.className = 'lp-featured-image-picker-dialog__header';

        var searchInput = document.createElement('input');
        searchInput.type = 'search';
        searchInput.className = 'lp-featured-image-picker-dialog__search';
        searchInput.placeholder = 'Search by filename or alt text…';
        searchInput.setAttribute('aria-label', 'Search images');

        var folderSelect = document.createElement('select');
        folderSelect.className = 'lp-featured-image-picker-dialog__folder-select';
        folderSelect.setAttribute('aria-label', 'Filter by folder');

        var allFoldersOption = document.createElement('option');
        allFoldersOption.value = '0';
        allFoldersOption.textContent = 'All Folders';
        folderSelect.appendChild(allFoldersOption);

        folders.forEach(function (folder) {
            var option = document.createElement('option');
            option.value = String(folder.id);
            option.textContent = new Array(folder.depth + 1).join('— ') + folder.name;
            folderSelect.appendChild(option);
        });

        header.appendChild(searchInput);
        header.appendChild(folderSelect);

        var status = document.createElement('p');
        status.className = 'lp-featured-image-picker-dialog__status';
        status.hidden = true;

        var grid = document.createElement('div');
        grid.className = 'lp-featured-image-picker-dialog__grid';

        var loadMoreButton = document.createElement('button');
        loadMoreButton.type = 'button';
        loadMoreButton.className = 'lp-button lp-featured-image-picker-dialog__load-more';
        loadMoreButton.textContent = 'Load More';
        loadMoreButton.hidden = true;

        var state = { term: '', folderId: 0, page: 1, loaded: 0, total: 0, requestId: 0 };

        function renderItem(item) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'lp-featured-image-picker-dialog__item';

            var img = document.createElement('img');
            img.src = gridThumbnailUrl(item);
            img.alt = item.name;
            button.appendChild(img);

            button.addEventListener('click', function () {
                onSelect(item);
                dialog.close();
            });

            grid.appendChild(button);
        }

        function fetchPage(reset) {
            var requestId = ++state.requestId;

            if (reset) {
                state.page = 1;
                state.loaded = 0;
                grid.innerHTML = '';
            }

            status.textContent = 'Loading…';
            status.hidden = false;
            loadMoreButton.hidden = true;

            var formData = new FormData();
            formData.append('form', 'featured_image_picker_query');
            formData.append('csrf_token', pickerCsrf);
            formData.append('term', state.term);
            formData.append('folder_id', String(state.folderId));
            formData.append('page', String(state.page));

            fetch(pickerUrl, { method: 'POST', body: formData })
                .then(function (response) { return response.json(); })
                .then(function (json) {
                    // Csrf::verify() is single-use — see
                    // content-editor.js's openMediaPicker() for why every
                    // response's fresh token replaces this one.
                    if (json.csrfToken) {
                        pickerCsrf = json.csrfToken;
                        container.dataset.pickerCsrf = json.csrfToken;
                    }

                    if (requestId !== state.requestId) {
                        return;
                    }

                    var items = json.items || [];
                    items.forEach(renderItem);
                    state.loaded += items.length;
                    state.total = json.total || 0;

                    if (state.loaded === 0) {
                        status.textContent = state.term !== '' || state.folderId > 0
                            ? 'No images match your search.'
                            : 'No images in the Media Manager yet.';
                        status.hidden = false;
                    } else {
                        status.hidden = true;
                    }

                    loadMoreButton.hidden = state.loaded >= state.total;
                })
                .catch(function () {
                    status.textContent = 'Could not load images. Try again.';
                    status.hidden = false;
                });
        }

        var searchTimer = null;
        searchInput.addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                state.term = searchInput.value.trim();
                fetchPage(true);
            }, 300);
        });

        folderSelect.addEventListener('change', function () {
            state.folderId = parseInt(folderSelect.value, 10) || 0;
            fetchPage(true);
        });

        loadMoreButton.addEventListener('click', function () {
            state.page += 1;
            fetchPage(false);
        });

        var closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'lp-button lp-featured-image-picker-dialog__close';
        closeButton.textContent = 'Cancel';
        closeButton.addEventListener('click', function () { dialog.close(); });

        dialog.appendChild(header);
        dialog.appendChild(status);
        dialog.appendChild(grid);
        dialog.appendChild(loadMoreButton);
        dialog.appendChild(closeButton);
        dialog.addEventListener('close', function () { dialog.remove(); });
        document.body.appendChild(dialog);
        dialog.showModal();

        fetchPage(true);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-lp-featured-image-picker]').forEach(function (container) {
            var trigger = container.querySelector('[data-picker-trigger]');
            var valueInput = container.querySelector('[data-picker-value]');
            var chosenLabel = container.querySelector('[data-picker-chosen]');
            var removeButton = container.querySelector('[data-picker-remove]');

            if (!trigger || !valueInput) {
                return;
            }

            function showChosen(item) {
                if (!chosenLabel) {
                    return;
                }

                chosenLabel.innerHTML = '';

                var thumb = document.createElement('img');
                thumb.className = 'lp-featured-image-picker__chosen-thumb';
                thumb.src = gridThumbnailUrl(item);
                thumb.alt = '';
                chosenLabel.appendChild(thumb);
                chosenLabel.appendChild(document.createTextNode(item.name));
            }

            trigger.addEventListener('click', function () {
                openFeaturedImagePicker(container, function (item) {
                    valueInput.value = String(item.id);
                    showChosen(item);

                    if (removeButton) {
                        removeButton.hidden = false;
                    }
                });
            });

            if (removeButton) {
                removeButton.addEventListener('click', function () {
                    valueInput.value = '0';

                    if (chosenLabel) {
                        chosenLabel.innerHTML = '';
                    }

                    removeButton.hidden = true;
                });
            }
        });
    });
})();
