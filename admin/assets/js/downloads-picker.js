/**
 * "Add from server" / "Replace file" picker (LPP-012), used by the
 * Add/Edit Download screen (admin/views/downloads/add-new.php).
 *
 * Markup contract:
 *   <div data-lp-download-picker
 *        data-picker-url="..."
 *        data-picker-csrf="..."
 *        data-media-folders='[{"id":1,"name":"...","depth":0}]'
 *        data-picker-radio="some-radio-id">   (optional — see below)
 *     <input type="hidden" data-picker-value>
 *     <button type="button" data-picker-trigger>Choose from Server…</button>
 *     <span data-picker-chosen></span>
 *   </div>
 *
 * A deliberately separate, much lighter component than
 * content-editor.js's own openMediaPicker() ("Insert Image"): that one
 * is hardcoded to images and has a whole Attachment Display Settings
 * step (size/link/alignment) that makes no sense for picking a
 * download's file — clicking a grid item here selects it immediately,
 * no second step. Queries its own `download_file_picker_query`
 * sub-action (see add-new.php), not `media_picker_query` — a Download's
 * file can be any type, not just images, and that other endpoint's
 * response shape (size variants for inline embedding) doesn't fit this
 * use case either.
 *
 * data-picker-radio names the id of a radio <input> (elsewhere on the
 * page) to auto-check on selection — the Add Download form's "Use an
 * existing file" choice, so choosing a file via the picker also
 * switches the Download source radio group to match without the admin
 * having to click both. The Edit screen's "Replace file" picker has no
 * such radio (there's only one source once a download already exists),
 * so that attribute is simply omitted there.
 */
(function () {
    'use strict';

    function openDownloadFilePicker(container, onSelect) {
        var folders = JSON.parse(container.dataset.mediaFolders || '[]');
        var pickerUrl = container.dataset.pickerUrl;
        var pickerCsrf = container.dataset.pickerCsrf;

        var dialog = document.createElement('dialog');
        dialog.className = 'lp-download-picker-dialog';

        var header = document.createElement('div');
        header.className = 'lp-download-picker-dialog__header';

        var searchInput = document.createElement('input');
        searchInput.type = 'search';
        searchInput.className = 'lp-download-picker-dialog__search';
        searchInput.placeholder = 'Search by filename…';
        searchInput.setAttribute('aria-label', 'Search files');

        var folderSelect = document.createElement('select');
        folderSelect.className = 'lp-download-picker-dialog__folder-select';
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
        status.className = 'lp-download-picker-dialog__status';
        status.hidden = true;

        var grid = document.createElement('div');
        grid.className = 'lp-download-picker-dialog__grid';

        var loadMoreButton = document.createElement('button');
        loadMoreButton.type = 'button';
        loadMoreButton.className = 'lp-button lp-download-picker-dialog__load-more';
        loadMoreButton.textContent = 'Load More';
        loadMoreButton.hidden = true;

        var state = { term: '', folderId: 0, page: 1, loaded: 0, total: 0, requestId: 0 };

        function renderItem(item) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'lp-download-picker-dialog__item';

            if (item.mimeType && item.mimeType.indexOf('image/') === 0) {
                var img = document.createElement('img');
                img.className = 'lp-download-picker-dialog__item-thumb';
                img.src = item.url;
                img.alt = '';
                button.appendChild(img);
            } else {
                var badge = document.createElement('span');
                badge.className = 'lp-download-picker-dialog__item-thumb lp-download-picker-dialog__item-thumb--file';
                badge.textContent = (item.typeCategory || 'file').toUpperCase();
                button.appendChild(badge);
            }

            var name = document.createElement('span');
            name.className = 'lp-download-picker-dialog__item-name';
            name.textContent = item.name;
            button.appendChild(name);

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
            formData.append('form', 'download_file_picker_query');
            formData.append('csrf_token', pickerCsrf);
            formData.append('term', state.term);
            formData.append('folder_id', String(state.folderId));
            formData.append('page', String(state.page));

            fetch(pickerUrl, { method: 'POST', body: formData })
                .then(function (response) { return response.json(); })
                .then(function (json) {
                    // Csrf::verify() is single-use — see openMediaPicker()'s
                    // identical note in content-editor.js for why every
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
                            ? 'No files match your search.'
                            : 'No files in the Media Manager yet.';
                        status.hidden = false;
                    } else {
                        status.hidden = true;
                    }

                    loadMoreButton.hidden = state.loaded >= state.total;
                })
                .catch(function () {
                    status.textContent = 'Could not load files. Try again.';
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
        closeButton.className = 'lp-button lp-download-picker-dialog__close';
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
        document.querySelectorAll('[data-lp-download-picker]').forEach(function (container) {
            var trigger = container.querySelector('[data-picker-trigger]');
            var valueInput = container.querySelector('[data-picker-value]');
            var chosenLabel = container.querySelector('[data-picker-chosen]');
            var radioId = container.dataset.pickerRadio;

            if (!trigger || !valueInput) {
                return;
            }

            trigger.addEventListener('click', function () {
                openDownloadFilePicker(container, function (item) {
                    valueInput.value = String(item.id);

                    if (chosenLabel) {
                        chosenLabel.textContent = item.name;
                    }

                    if (radioId) {
                        var radio = document.getElementById(radioId);

                        if (radio) {
                            radio.checked = true;
                        }
                    }
                });
            });
        });
    });
})();
