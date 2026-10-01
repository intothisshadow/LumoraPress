/**
 * Content Editor orchestration (LP-015 Markdown / LP-016 WYSIWYG).
 *
 * Markup contract (see admin/views/posts/new.php / pages/new.php):
 *   <select data-lp-content-format-select>          — Markdown/HTML/Plain
 *   <div data-lp-content-editor
 *        data-format="markdown|html|plain"
 *        data-upload-url="..."
 *        data-upload-csrf="..."
 *        data-convert-csrf="..."
 *        data-media-picker-csrf="..."
 *        data-link-picker-csrf="..."
 *        data-media-folders='[{"id":1,"name":"...","depth":0}]'
 *        data-icon-picker-csrf="..."                    — omitted entirely
 *        data-icon-picker-css='["https://...css"]'         when the Font
 *        data-shortcodes='{"icon":{"label":...,           Awesome plugin
 *          "fields":[...]},...}'>                          (LPP-002) is
 *                                                          disabled — see
 *                                                          openIconPicker().
 *     <textarea>...</textarea>
 *   </div>
 *
 * data-shortcodes (LP-110) is ShortcodeManager::toArray() JSON-encoded —
 * whichever shortcodes are actually registered this request (only
 * active plugins register), read by openShortcodePicker() below. Empty
 * ('{}', the default when the attribute is omitted) hides the "Insert
 * Shortcode" toolbar button entirely rather than showing an always-empty
 * picker.
 *
 * LP-115: the "Insert Image" picker (openMediaPicker() below) no longer
 * receives a preloaded library array — data-upload-url doubles as the
 * picker's own query endpoint (POST form=media_picker_query, same page
 * editor_upload/convert_content already target), paginated 40 images at
 * a time so opening the picker never has to load a whole library up
 * front. Only the (small) Folder tree is still preloaded, via
 * data-media-folders, since the filter <select> needs it immediately.
 *
 * The <textarea> is always the real form field — both EasyMDE and TinyMCE
 * are told to keep it in sync (EasyMDE does this natively; the TinyMCE
 * adapter below copies on every change), so content still submits even if
 * a CDN library fails to load. That's also why format-switching doesn't
 * need to special-case "editor not initialized yet": the textarea's value
 * is always the source of truth.
 *
 * EasyMDE (Markdown) and TinyMCE (HTML/WYSIWYG) are both loaded from
 * jsDelivr at a pinned version rather than vendored locally — the same
 * project decision admin/assets/js/media-viewer.js's docblock explains
 * for PhotoSwipe, extended here to the two much larger editor libraries
 * this ticket pair calls for. Both are self-hosted-mode (no cloud API
 * key/nag): jsDelivr serves the plain open-source package.
 */
(function () {
    'use strict';

    var EASYMDE_VERSION = '2.18.0';
    var EASYMDE_JS = 'https://cdn.jsdelivr.net/npm/easymde@' + EASYMDE_VERSION + '/dist/easymde.min.js';
    var EASYMDE_CSS = 'https://cdn.jsdelivr.net/npm/easymde@' + EASYMDE_VERSION + '/dist/easymde.min.css';
    // EasyMDE's own CSS styles its default toolbar buttons (bold, italic,
    // lists, link, image, preview, fullscreen, ...) as Font Awesome 4
    // glyphs — with autoDownloadFontAwesome off (see below) and no font
    // loaded, every one of those buttons renders blank. Loaded explicitly
    // here (same cdn.jsdelivr.net origin already CSP-allowed) instead of
    // via EasyMDE's own auto-download, which targets a URL/origin outside
    // this app's control and so can't be reliably pre-allowed in the CSP.
    var FONT_AWESOME_CSS = 'https://cdn.jsdelivr.net/npm/font-awesome@4.7.0/css/font-awesome.min.css';
    var TINYMCE_VERSION = '7';
    var TINYMCE_JS = 'https://cdn.jsdelivr.net/npm/tinymce@' + TINYMCE_VERSION + '/tinymce.min.js';

    // Fixed font-color palette (LP-016 parity), shared by both editors.
    // Applied as a has-{name}-color class (content/themes/lumora-classic/style.css)
    // rather than an inline style="color:..." — HtmlSanitizer never allows
    // a style attribute (see its docblock), so an arbitrary color picker
    // isn't an option here. MarkdownParser::FONT_COLORS carries the same
    // list of names; keep both in sync by hand if this ever changes.
    var FONT_COLORS = [
        { name: 'red', label: 'Red', swatch: '#c0392b' },
        { name: 'orange', label: 'Orange', swatch: '#d35400' },
        { name: 'yellow', label: 'Yellow', swatch: '#b7950b' },
        { name: 'green', label: 'Green', swatch: '#1e8449' },
        { name: 'blue', label: 'Blue', swatch: '#2471a3' },
        { name: 'purple', label: 'Purple', swatch: '#7d3c98' },
        { name: 'gray', label: 'Gray', swatch: '#616a6b' },
    ];

    var loadedScripts = {};
    var loadedStyles = {};

    function loadScript(url) {
        if (loadedScripts[url]) {
            return loadedScripts[url];
        }

        loadedScripts[url] = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = url;
            script.onload = function () { resolve(); };
            script.onerror = function () { reject(new Error('Failed to load ' + url)); };
            document.head.appendChild(script);
        });

        return loadedScripts[url];
    }

    function loadStyle(url) {
        if (loadedStyles[url]) {
            return;
        }

        loadedStyles[url] = true;
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;
        document.head.appendChild(link);
    }

    function wordCount(text) {
        var trimmed = text.trim();

        return trimmed === '' ? 0 : trimmed.split(/\s+/).length;
    }

    function readingTimeMinutes(words) {
        return Math.max(1, Math.round(words / 200));
    }

    function updateStats(statsEl, text) {
        if (!statsEl) {
            return;
        }

        var words = wordCount(text);
        var chars = text.length;
        var minutes = readingTimeMinutes(words);

        statsEl.textContent = words + ' word' + (words === 1 ? '' : 's') + ' · '
            + chars + ' character' + (chars === 1 ? '' : 's') + ' · '
            + '~' + minutes + ' min read';
    }

    // ------------------------------------------------------------------
    // Media picker — shared by both editors and by both the "Insert
    // Image" toolbar button and the native image-dialog replacement
    // (LP-115: the two used to be separate entry points on the same
    // toolbar). Queries the "Insert Image" media picker's own
    // media_picker_query sub-action (POST to data-upload-url — the same
    // page editor_upload/convert_content already target), paginated 40
    // images at a time rather than the whole library preloaded up
    // front, with client-side-triggered search/Folder filtering handled
    // server-side per request.
    //
    // LP-075: picking an image is still a two-step flow — the grid, then
    // an "Attachment Display Settings" step (Size / Link To) before the
    // callback fires, mirroring classic WordPress's Insert Media dialog.
    // Each item's `sizes` map ({full, small?, medium?, large?}, each
    // {url, width, height} — see PostsController::buildEditorPickerItem())
    // is built server-side so this step needs no extra request.
    // ------------------------------------------------------------------

    var SIZE_LABELS = { small: 'Thumbnail', medium: 'Medium', large: 'Large', full: 'Full Size' };
    var SIZE_ORDER = ['small', 'medium', 'large', 'full'];
    var MEDIA_PICKER_PAGE_SIZE = 40;

    function escapeHtmlAttr(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // For a `[shortcode attr="value"]` attribute value (openShortcodePicker()
    // below) rather than a real HTML attribute — the bracket syntax's own
    // parser (e.g. FontAwesomeService::parseShortcodeAttributes()) matches
    // up to the next literal '"' with no escape mechanism of its own, so
    // HTML-entity-escaping a value here (escapeHtmlAttr's job) would just
    // store literal "&quot;" text instead of protecting anything. Simply
    // dropping any '"' the value contains is what actually keeps it from
    // breaking out of its own quotes, whether the shortcode ends up inside
    // Markdown or TinyMCE-authored HTML.
    function shortcodeAttrValue(value) {
        return String(value).replace(/"/g, '');
    }

    // A Width/Height input with a px/% unit, shared by Insert Image and Edit
    // Image. spec() is '' (leave the natural size) or a whole number with a
    // unit, e.g. '300px' / '50%' — the form the server-side image markers
    // and data-style attributes accept.
    function createDimensionField(labelText, idSuffix) {
        var field = document.createElement('p');
        field.className = 'lp-field lp-editor-dimension';

        var label = document.createElement('label');
        label.textContent = labelText;

        var input = document.createElement('input');
        input.type = 'number';
        input.min = '1';
        input.max = '9999';
        input.step = '1';
        input.placeholder = 'Auto';
        input.id = 'lp-editor-image-' + idSuffix;
        label.htmlFor = input.id;

        var unit = document.createElement('select');
        unit.setAttribute('aria-label', labelText + ' unit');

        [['px', 'px'], ['%', '%']].forEach(function (pair) {
            var option = document.createElement('option');
            option.value = pair[0];
            option.textContent = pair[1];
            unit.appendChild(option);
        });

        var row = document.createElement('span');
        row.className = 'lp-editor-dimension__row';
        row.appendChild(input);
        row.appendChild(unit);

        field.appendChild(label);
        field.appendChild(row);

        return {
            element: field,
            setSpec: function (spec) {
                var match = /^([1-9][0-9]{0,3})(px|%)$/.exec(spec || '');
                input.value = match ? match[1] : '';
                unit.value = match ? match[2] : 'px';
            },
            spec: function () {
                var number = parseInt(input.value, 10);

                if (!number || number < 1) {
                    return '';
                }

                return (unit.value === '%' ? Math.min(number, 100) : number) + unit.value;
            },
        };
    }

    // The attribute map for an image's size. With an explicit width and/or
    // height: width/height attributes (a bare number for pixels) plus the
    // data-style-* copies dynamic-style.js applies, since a theme's
    // `height: auto` would ignore a height and inline styles are blocked by
    // the CSP. A dimension left out is "auto", so one can't distort the other.
    // Without either, the natural width/height of the chosen size, as before.
    function imageSizeAttributes(widthSpec, heightSpec, naturalWidth, naturalHeight) {
        var attributes = {};

        if (!widthSpec && !heightSpec) {
            if (naturalWidth) {
                attributes.width = String(naturalWidth);
            }

            if (naturalHeight) {
                attributes.height = String(naturalHeight);
            }

            return attributes;
        }

        [['width', widthSpec], ['height', heightSpec]].forEach(function (pair) {
            if (pair[1]) {
                attributes[pair[0]] = pair[1].slice(-1) === '%' ? pair[1] : String(parseInt(pair[1], 10));
                attributes['data-style-' + pair[0]] = pair[1];
            } else {
                attributes['data-style-' + pair[0]] = 'auto';
            }
        });

        return attributes;
    }

    function imageSizeHtml(payload) {
        var attributes = imageSizeAttributes(payload.widthSpec, payload.heightSpec, payload.width, payload.height);

        return Object.keys(attributes).map(function (name) {
            return ' ' + name + '="' + attributes[name] + '"';
        }).join('');
    }

    // Markdown has no attribute syntax, so a size rides in the same trailing
    // {…} marker convention as alignment (MarkdownParser::parseImages()).
    function imageSizeMarkdown(payload) {
        return (payload.widthSpec ? '{width=' + payload.widthSpec + '}' : '') + (payload.heightSpec ? '{height=' + payload.heightSpec + '}' : '');
    }

    // The Markdown for one image from an Insert/Edit Image payload. A caption
    // rides in the image title plus a {.caption} marker and only becomes a
    // <figure> when the image is a paragraph of its own; a straight double
    // quote would end the title early.
    function buildMarkdownImage(payload) {
        var alignMarker = payload.align && payload.align !== 'alignnone' ? '{.' + payload.align + '}' : '';
        var optedOut = payload.noLightbox !== undefined ? payload.noLightbox : !payload.linkUrl;
        var noLightboxMarker = optedOut ? '{.no-lightbox}' : '';
        var title = payload.caption ? ' "' + payload.caption.replace(/"/g, '\u201D') + '"' : '';
        var captionMarker = payload.caption ? '{.caption}' : '';
        var image = '![' + payload.alt + '](' + payload.url + title + ')' + alignMarker + noLightboxMarker + captionMarker + imageSizeMarkdown(payload);

        return payload.linkUrl ? '[' + image + '](' + payload.linkUrl + ')' : image;
    }

    // Prefers the smallest generated thumbnail for the grid tile — the
    // full-size original (possibly several megapixels) would otherwise
    // load 40-at-a-time for nothing more than a small square preview.
    function gridThumbnailUrl(item) {
        var sizes = item.sizes || {};

        return (sizes.small || sizes.medium || sizes.large || sizes.full || { url: item.url }).url;
    }

    function openMediaPicker(container, onSelect) {
        var folders = JSON.parse(container.dataset.mediaFolders || '[]');
        var pickerUrl = container.dataset.uploadUrl;
        var pickerCsrf = container.dataset.mediaPickerCsrf;

        var dialog = document.createElement('dialog');
        dialog.className = 'lp-editor-media-dialog';

        var header = document.createElement('div');
        header.className = 'lp-editor-media-dialog__header';

        var searchInput = document.createElement('input');
        searchInput.type = 'search';
        searchInput.className = 'lp-editor-media-dialog__search';
        searchInput.placeholder = 'Search by filename or alt text…';
        searchInput.setAttribute('aria-label', 'Search images');

        var folderSelect = document.createElement('select');
        folderSelect.className = 'lp-editor-media-dialog__folder-select';
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

        var uploadLabel = document.createElement('label');
        uploadLabel.className = 'lp-button lp-button--secondary lp-editor-media-dialog__upload-button';
        uploadLabel.textContent = 'Upload New';
        var uploadInput = document.createElement('input');
        uploadInput.type = 'file';
        uploadInput.accept = 'image/*';
        uploadInput.hidden = true;
        uploadLabel.appendChild(uploadInput);

        header.appendChild(searchInput);
        header.appendChild(folderSelect);
        header.appendChild(uploadLabel);

        var status = document.createElement('p');
        status.className = 'lp-editor-media-dialog__status';
        status.hidden = true;

        var grid = document.createElement('div');
        grid.className = 'lp-editor-media-dialog__grid';

        var loadMoreButton = document.createElement('button');
        loadMoreButton.type = 'button';
        loadMoreButton.className = 'lp-button lp-editor-media-dialog__load-more';
        loadMoreButton.textContent = 'Load More';
        loadMoreButton.hidden = true;

        var settings = document.createElement('div');
        settings.className = 'lp-editor-media-dialog__settings';
        settings.hidden = true;

        var state = { term: '', folderId: 0, page: 1, loaded: 0, total: 0, requestId: 0 };

        function renderItem(item) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'lp-editor-media-dialog__item';

            var img = document.createElement('img');
            img.src = gridThumbnailUrl(item);
            img.alt = item.name;
            button.appendChild(img);

            button.addEventListener('click', function () {
                showSettingsStep(item);
            });

            grid.appendChild(button);
        }

        function showGridStep() {
            settings.hidden = true;
            header.hidden = false;
            grid.hidden = false;
            status.hidden = state.loaded !== 0;
            loadMoreButton.hidden = state.loaded >= state.total;
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
            formData.append('form', 'media_picker_query');
            formData.append('csrf_token', pickerCsrf);
            formData.append('term', state.term);
            formData.append('folder_id', String(state.folderId));
            formData.append('page', String(state.page));

            fetch(pickerUrl, { method: 'POST', body: formData })
                .then(function (response) { return response.json(); })
                .then(function (json) {
                    // Csrf::verify() is single-use — every response
                    // (including a stale one about to be discarded below)
                    // carries a freshly issued token that must replace
                    // this one for the *next* query, or that next request
                    // fails verification (matches uploadFile()'s identical
                    // pattern). Written back onto the container's own
                    // dataset too, so the token survives closing and
                    // reopening the dialog, not just this one session.
                    if (json.csrfToken) {
                        pickerCsrf = json.csrfToken;
                        container.dataset.mediaPickerCsrf = json.csrfToken;
                    }

                    // A later search/folder change may have already
                    // started its own request — an out-of-order response
                    // to this now-stale one must never repopulate the grid.
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

        uploadInput.addEventListener('change', function () {
            var file = uploadInput.files[0];

            if (!file) {
                return;
            }

            uploadLabel.classList.add('is-uploading');

            uploadFile(container, file)
                .then(function (json) {
                    showSettingsStep(json.item);
                })
                .catch(function (error) {
                    window.alert(error.message || 'Upload failed.');
                })
                .finally(function () {
                    uploadLabel.classList.remove('is-uploading');
                    uploadInput.value = '';
                });
        });

        function showSettingsStep(item) {
            settings.innerHTML = '';
            header.hidden = true;
            grid.hidden = true;
            status.hidden = true;
            loadMoreButton.hidden = true;
            settings.hidden = false;

            var itemSizes = item.sizes || {};

            var sizeField = document.createElement('p');
            sizeField.className = 'lp-field';
            var sizeLabelEl = document.createElement('label');
            sizeLabelEl.textContent = 'Size';
            var sizeSelect = document.createElement('select');

            SIZE_ORDER.forEach(function (name) {
                if (!itemSizes[name]) {
                    return;
                }

                var option = document.createElement('option');
                option.value = name;
                option.textContent = SIZE_LABELS[name] + ' (' + itemSizes[name].width + '×' + itemSizes[name].height + ')';
                option.selected = name === 'full';
                sizeSelect.appendChild(option);
            });

            sizeField.appendChild(sizeLabelEl);
            sizeField.appendChild(sizeSelect);

            var linkField = document.createElement('p');
            linkField.className = 'lp-field';
            var linkLabelEl = document.createElement('label');
            linkLabelEl.textContent = 'Link To';
            var linkSelect = document.createElement('select');

            [['none', 'None'], ['file', 'Media File']].forEach(function (pair) {
                var option = document.createElement('option');
                option.value = pair[0];
                option.textContent = pair[1];
                linkSelect.appendChild(option);
            });

            linkField.appendChild(linkLabelEl);
            linkField.appendChild(linkSelect);

            var alignField = document.createElement('p');
            alignField.className = 'lp-field';
            var alignLabelEl = document.createElement('label');
            alignLabelEl.textContent = 'Alignment';
            var alignSelect = document.createElement('select');

            [['alignnone', 'None'], ['alignleft', 'Left'], ['aligncenter', 'Center'], ['alignright', 'Right']].forEach(function (pair) {
                var option = document.createElement('option');
                option.value = pair[0];
                option.textContent = pair[1];
                alignSelect.appendChild(option);
            });

            alignField.appendChild(alignLabelEl);
            alignField.appendChild(alignSelect);

            var widthField = createDimensionField('Width', 'insert-width');
            var heightField = createDimensionField('Height', 'insert-height');
            var dimensionHint = document.createElement('p');
            dimensionHint.className = 'lp-field__hint';
            dimensionHint.textContent = 'Optional. Leave both empty to use the size chosen above; a percentage is of the content width.';

            // Pre-filled from the media item's own Caption field, but
            // editable per insertion, like classic WordPress. Left empty,
            // the image is inserted exactly as before (no <figure>).
            var captionField = document.createElement('p');
            captionField.className = 'lp-field';
            var captionLabelEl = document.createElement('label');
            captionLabelEl.textContent = 'Caption';
            var captionInput = document.createElement('textarea');
            captionInput.rows = 2;
            captionInput.value = item.caption || '';
            captionInput.id = 'lp-editor-media-caption';
            captionLabelEl.htmlFor = captionInput.id;

            captionField.appendChild(captionLabelEl);
            captionField.appendChild(captionInput);

            var actions = document.createElement('div');
            actions.className = 'lp-editor-media-dialog__settings-actions';

            var insertButton = document.createElement('button');
            insertButton.type = 'button';
            insertButton.className = 'lp-button lp-button--primary';
            insertButton.textContent = 'Insert';
            insertButton.addEventListener('click', function () {
                var chosen = itemSizes[sizeSelect.value] || { url: item.url, width: 0, height: 0 };
                var full = itemSizes.full || { url: item.url, width: 0, height: 0 };

                onSelect({
                    url: chosen.url,
                    width: chosen.width,
                    height: chosen.height,
                    size: sizeSelect.value,
                    align: alignSelect.value,
                    alt: item.alt || item.name,
                    widthSpec: widthField.spec(),
                    heightSpec: heightField.spec(),
                    caption: captionInput.value.replace(/\s+/g, ' ').trim(),
                    linkUrl: linkSelect.value === 'file' ? full.url : null,
                    // The *linked* file's own dimensions — only
                    // meaningful (and only ever different from width/
                    // height above) when linkUrl is set, since a chosen
                    // display size can differ from Full. Passed through
                    // separately so a lightbox opened on this image sizes
                    // itself against the file it actually links to, not
                    // the inline thumbnail's own smaller size.
                    linkWidth: linkSelect.value === 'file' ? full.width : 0,
                    linkHeight: linkSelect.value === 'file' ? full.height : 0,
                });
                dialog.close();
            });

            var backButton = document.createElement('button');
            backButton.type = 'button';
            backButton.className = 'lp-button';
            backButton.textContent = 'Back';
            backButton.addEventListener('click', showGridStep);

            actions.appendChild(insertButton);
            actions.appendChild(backButton);

            settings.appendChild(sizeField);
            settings.appendChild(widthField.element);
            settings.appendChild(heightField.element);
            settings.appendChild(dimensionHint);
            settings.appendChild(linkField);
            settings.appendChild(alignField);
            settings.appendChild(captionField);
            settings.appendChild(actions);
        }

        var closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'lp-button lp-editor-media-dialog__close';
        closeButton.textContent = 'Cancel';
        closeButton.addEventListener('click', function () { dialog.close(); });

        dialog.appendChild(header);
        dialog.appendChild(status);
        dialog.appendChild(grid);
        dialog.appendChild(loadMoreButton);
        dialog.appendChild(settings);
        dialog.appendChild(closeButton);
        dialog.addEventListener('close', function () { dialog.remove(); });
        document.body.appendChild(dialog);
        dialog.showModal();

        fetchPage(true);
    }

    /**
     * "Edit Image": the settings an existing image can change in place —
     * alt text, caption, alignment, link, and width/height. Shared by both
     * editors; each supplies the current values and applies the result.
     * Changing which file is shown means inserting the image again.
     *
     * @param {{alt: string, caption: string, align: string, linked: boolean, widthSpec: string, heightSpec: string}} current
     * @param {function(Object): void} onApply
     */
    function openImageEditDialog(current, onApply) {
        var dialog = document.createElement('dialog');
        dialog.className = 'lp-editor-media-dialog lp-editor-media-dialog--edit-image';

        var heading = document.createElement('h2');
        heading.className = 'lp-editor-media-dialog__title';
        heading.textContent = 'Edit Image';

        var settings = document.createElement('div');
        settings.className = 'lp-editor-media-dialog__settings';

        function textField(labelText, id, value) {
            var field = document.createElement('p');
            field.className = 'lp-field';
            var label = document.createElement('label');
            label.textContent = labelText;
            label.htmlFor = id;
            var input = document.createElement('input');
            input.type = 'text';
            input.id = id;
            input.value = value;
            field.appendChild(label);
            field.appendChild(input);

            return { element: field, input: input };
        }

        function selectField(labelText, id, pairs, value) {
            var field = document.createElement('p');
            field.className = 'lp-field';
            var label = document.createElement('label');
            label.textContent = labelText;
            label.htmlFor = id;
            var select = document.createElement('select');
            select.id = id;

            pairs.forEach(function (pair) {
                var option = document.createElement('option');
                option.value = pair[0];
                option.textContent = pair[1];
                select.appendChild(option);
            });

            select.value = value;
            field.appendChild(label);
            field.appendChild(select);

            return { element: field, select: select };
        }

        var alt = textField('Alternative text', 'lp-editor-edit-image-alt', current.alt);

        var captionField = document.createElement('p');
        captionField.className = 'lp-field';
        var captionLabel = document.createElement('label');
        captionLabel.textContent = 'Caption';
        var captionInput = document.createElement('textarea');
        captionInput.rows = 2;
        captionInput.id = 'lp-editor-edit-image-caption';
        captionInput.value = current.caption;
        captionLabel.htmlFor = captionInput.id;
        captionField.appendChild(captionLabel);
        captionField.appendChild(captionInput);

        var align = selectField('Alignment', 'lp-editor-edit-image-align', [['alignnone', 'None'], ['alignleft', 'Left'], ['aligncenter', 'Center'], ['alignright', 'Right']], current.align);
        var link = selectField('Link To', 'lp-editor-edit-image-link', [['none', 'None'], ['file', 'Media File']], current.linked ? 'file' : 'none');
        var widthField = createDimensionField('Width', 'edit-width');
        var heightField = createDimensionField('Height', 'edit-height');
        widthField.setSpec(current.widthSpec);
        heightField.setSpec(current.heightSpec);

        var hint = document.createElement('p');
        hint.className = 'lp-field__hint';
        hint.textContent = 'Leave width and height empty to keep the image\u2019s natural size; a percentage is of the content width.';

        var actions = document.createElement('div');
        actions.className = 'lp-editor-media-dialog__settings-actions';

        var updateButton = document.createElement('button');
        updateButton.type = 'button';
        updateButton.className = 'lp-button lp-button--primary';
        updateButton.textContent = 'Update';
        updateButton.addEventListener('click', function () {
            onApply({
                alt: alt.input.value.trim(),
                caption: captionInput.value.replace(/\s+/g, ' ').trim(),
                align: align.select.value,
                link: link.select.value,
                widthSpec: widthField.spec(),
                heightSpec: heightField.spec(),
            });
            dialog.close();
        });

        var cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className = 'lp-button';
        cancelButton.textContent = 'Cancel';
        cancelButton.addEventListener('click', function () { dialog.close(); });

        actions.appendChild(updateButton);
        actions.appendChild(cancelButton);

        [alt.element, captionField, align.element, link.element, widthField.element, heightField.element, hint, actions].forEach(function (node) {
            settings.appendChild(node);
        });

        dialog.appendChild(heading);
        dialog.appendChild(settings);
        dialog.addEventListener('close', function () { dialog.remove(); });
        document.body.appendChild(dialog);
        dialog.showModal();
        alt.input.focus();
    }

    var IMAGE_ALIGN_CLASSES = ['alignleft', 'aligncenter', 'alignright'];

    function isEditableImage(node) {
        return !!node && node.nodeName === 'IMG' && !node.hasAttribute('data-mce-object') && !node.classList.contains('mce-pagebreak');
    }

    // Reads an editor image's current settings back out of its markup: the
    // alignment lives on the <figure> when captioned, the link is its
    // wrapping <a>, and an explicit size is the data-style copy (or a
    // percentage attribute) rather than the natural pixel size.
    function readTinyMceImage(editor, img) {
        var figure = editor.dom.getParent(img, 'figure.lp-caption');
        var link = editor.dom.getParent(img, 'a');
        var classes = String((figure || img).className).split(/\s+/);
        var captionElement = figure ? figure.querySelector('figcaption') : null;

        function sizeSpec(dimension) {
            var styled = img.getAttribute('data-style-' + dimension);

            if (styled && styled !== 'auto') {
                return styled;
            }

            var attribute = img.getAttribute(dimension) || '';

            return /^[1-9][0-9]{0,3}%$/.test(attribute) ? attribute : '';
        }

        return {
            alt: img.getAttribute('alt') || '',
            caption: captionElement ? captionElement.textContent.replace(/\s+/g, ' ').trim() : '',
            align: IMAGE_ALIGN_CLASSES.filter(function (name) { return classes.indexOf(name) !== -1; })[0] || 'alignnone',
            // No link and no no-lightbox class: the image opens in the lightbox by itself.
            linked: link !== null || !img.classList.contains('no-lightbox'),
            widthSpec: sizeSpec('width'),
            heightSpec: sizeSpec('height'),
        };
    }

    // Rewrites the selected image in place, as a single undo step. Alt, size,
    // alignment, caption (the <figure>) and link (the <a>, or no-lightbox
    // when unlinked) mirror what Insert Image produces.
    function applyTinyMceImage(editor, img, settings) {
        var dom = editor.dom;

        editor.undoManager.transact(function () {
            dom.setAttrib(img, 'alt', settings.alt);

            ['width', 'height', 'data-style-width', 'data-style-height'].forEach(function (name) {
                img.removeAttribute(name);
            });

            var attributes = imageSizeAttributes(settings.widthSpec, settings.heightSpec, img.naturalWidth, img.naturalHeight);
            Object.keys(attributes).forEach(function (name) {
                img.setAttribute(name, attributes[name]);
            });

            var link = dom.getParent(img, 'a');
            var figure = dom.getParent(img, 'figure.lp-caption');
            var hadNoLightbox = img.classList.contains('no-lightbox');

            IMAGE_ALIGN_CLASSES.forEach(function (name) {
                dom.removeClass(img, name);

                if (figure) {
                    dom.removeClass(figure, name);
                }
            });

            if (settings.link === 'none') {
                if (link) {
                    link.parentNode.insertBefore(img, link);
                    link.parentNode.removeChild(link);
                    link = null;
                }

                dom.addClass(img, 'no-lightbox');
            } else {
                dom.removeClass(img, 'no-lightbox');

                // An image that already opens in the lightbox by itself needs no link added.
                if (!link && hadNoLightbox) {
                    link = dom.create('a', { href: img.getAttribute('src') });

                    if (img.naturalWidth) {
                        link.setAttribute('data-pswp-width', String(img.naturalWidth));
                        link.setAttribute('data-pswp-height', String(img.naturalHeight));
                    }

                    img.parentNode.insertBefore(link, img);
                    link.appendChild(img);
                }

                if (link) {
                    link.setAttribute('data-pswp-caption', settings.caption || settings.alt);
                }
            }

            var outer = link || img;
            figure = dom.getParent(outer, 'figure.lp-caption');

            if (settings.caption) {
                if (!figure) {
                    figure = dom.create('figure', { 'class': 'lp-caption' });
                    outer.parentNode.insertBefore(figure, outer);
                    figure.appendChild(outer);

                    // A figure can't sit inside a paragraph; replace one that held only the image.
                    var holder = figure.parentNode;

                    if (holder && holder.nodeName === 'P' && holder.childNodes.length === 1) {
                        holder.parentNode.insertBefore(figure, holder);
                        holder.parentNode.removeChild(holder);
                    }
                }

                var captionElement = figure.querySelector('figcaption');

                if (!captionElement) {
                    captionElement = dom.create('figcaption');
                    figure.appendChild(captionElement);
                }

                captionElement.textContent = settings.caption;

                if (settings.align !== 'alignnone') {
                    dom.addClass(figure, settings.align);
                }
            } else {
                if (figure) {
                    var paragraph = dom.create('p');
                    figure.parentNode.insertBefore(paragraph, figure);
                    paragraph.appendChild(outer);
                    figure.parentNode.removeChild(figure);
                }

                if (settings.align !== 'alignnone') {
                    dom.addClass(img, settings.align);
                }
            }
        });

        editor.selection.select(img);
        editor.nodeChanged();
    }

    function openTinyMceImageEditor(editor) {
        var img = editor.selection.getNode();

        if (!isEditableImage(img)) {
            return;
        }

        openImageEditDialog(readTinyMceImage(editor, img), function (settings) {
            editor.focus();
            applyTinyMceImage(editor, img, settings);
        });
    }

    // Finds the Markdown image (optionally wrapped in a link) the cursor is
    // in, on the cursor's own line: ![alt](url "caption") plus trailing
    // {…} markers. Returns null when the cursor isn't inside one.
    function findMarkdownImageAtCursor(cm) {
        var cursor = cm.getCursor();
        var text = cm.getLine(cursor.line);
        var markers = '((?:\\{\\.(?:alignleft|aligncenter|alignright|no-lightbox|caption)\\}|\\{(?:width|height)=[0-9]{1,4}(?:px|%)?\\})*)';
        var image = '!\\[([^\\]]*)\\]\\(\\s*(<[^>]*>|[^\\s)]+)(?:\\s+"([^"]*)")?\\s*\\)' + markers;
        var patterns = [
            { regex: new RegExp('\\[' + image + '\\]\\(\\s*(<[^>]*>|[^\\s)]+)\\s*\\)', 'g'), linked: true },
            { regex: new RegExp(image, 'g'), linked: false },
        ];

        for (var index = 0; index < patterns.length; index++) {
            var match;
            patterns[index].regex.lastIndex = 0;

            while ((match = patterns[index].regex.exec(text)) !== null) {
                var start = match.index;
                var end = start + match[0].length;

                if (cursor.ch >= start && cursor.ch <= end) {
                    return {
                        line: cursor.line,
                        start: start,
                        end: end,
                        alt: match[1],
                        url: match[2].replace(/^<|>$/g, ''),
                        caption: match[3] || '',
                        markers: match[4] || '',
                        linkUrl: patterns[index].linked ? match[5].replace(/^<|>$/g, '') : null,
                        wholeLine: start === 0 && end === text.length,
                    };
                }
            }
        }

        return null;
    }

    function openMarkdownImageEditor(cm) {
        var found = findMarkdownImageAtCursor(cm);

        if (found === null) {
            window.alert('Place the cursor inside an image to edit it.');

            return;
        }

        var align = (/\{\.(alignleft|aligncenter|alignright)\}/.exec(found.markers) || [])[1] || 'alignnone';
        var width = /\{width=([0-9]{1,4})(px|%)?\}/.exec(found.markers);
        var height = /\{height=([0-9]{1,4})(px|%)?\}/.exec(found.markers);

        openImageEditDialog({
            alt: found.alt,
            caption: found.caption,
            align: align,
            // An image with no link and no {.no-lightbox} marker opens in the
            // lightbox on its own, which is the same as linking to its file.
            linked: found.linkUrl !== null || found.markers.indexOf('{.no-lightbox}') === -1,
            widthSpec: width ? width[1] + (width[2] || 'px') : '',
            heightSpec: height ? height[1] + (height[2] || 'px') : '',
        }, function (settings) {
            var replacement = buildMarkdownImage({
                url: found.url,
                alt: settings.alt,
                caption: settings.caption,
                align: settings.align,
                // A link kept is the one already there. With none, the image either
                // keeps opening in the lightbox by itself or is opted out of it.
                linkUrl: settings.link === 'file' ? found.linkUrl : null,
                noLightbox: settings.link === 'file' ? false : true,
                widthSpec: settings.widthSpec,
                heightSpec: settings.heightSpec,
            });

            // A caption only makes a figure when the image is a paragraph of its own.
            if (settings.caption && !found.wholeLine) {
                replacement = '\n\n' + replacement + '\n\n';
            }

            cm.replaceRange(replacement, { line: found.line, ch: found.start }, { line: found.line, ch: found.end });
            cm.focus();
        });
    }

    /**
     * LP-122: "Insert Folder" — a much lighter dialog than
     * openMediaPicker() above, since it needs no paginated grid query
     * of its own. The folder list is already preloaded via
     * data-media-folders (LP-115), used until now only for
     * openMediaPicker()'s own *filter* dropdown — reused here as the
     * required folder-to-insert choice. onInsert receives
     * {folderId, link, size} ('link' is 'none'/'file', matching the
     * Insert Image "Link To" select's own value/label pair exactly, so
     * the two pickers stay visually consistent; 'size' reuses the same
     * SIZE_LABELS/SIZE_ORDER Insert Image's own Size field uses).
     *
     * The Size field exists so the gallery can request Medium/Large/Full
     * instead of always the cropped 150px "Thumbnail" grid — and so
     * "Thumbnail" itself renders correctly for an image smaller than
     * 150px, which ThumbnailService never upscales to fill: see
     * FolderGalleryShortcode::resolveImage()'s identical reasoning for
     * why the shortcode never claims dimensions bigger than the file it
     * actually links to.
     */
    function openFolderGalleryPicker(container, onInsert) {
        var folders = JSON.parse(container.dataset.mediaFolders || '[]');

        var dialog = document.createElement('dialog');
        dialog.className = 'lp-editor-media-dialog';

        var heading = document.createElement('h2');
        heading.textContent = 'Insert Folder';
        heading.className = 'lp-editor-media-dialog__heading';

        var folderField = document.createElement('p');
        folderField.className = 'lp-field';
        var folderLabelEl = document.createElement('label');
        folderLabelEl.textContent = 'Folder';
        var folderSelect = document.createElement('select');

        folders.forEach(function (folder) {
            var option = document.createElement('option');
            option.value = String(folder.id);
            option.textContent = new Array(folder.depth + 1).join('— ') + folder.name;
            folderSelect.appendChild(option);
        });

        folderField.appendChild(folderLabelEl);
        folderField.appendChild(folderSelect);

        var sizeField = document.createElement('p');
        sizeField.className = 'lp-field';
        var sizeLabelEl = document.createElement('label');
        sizeLabelEl.textContent = 'Size';
        var sizeSelect = document.createElement('select');

        SIZE_ORDER.forEach(function (name) {
            var option = document.createElement('option');
            option.value = name;
            option.textContent = SIZE_LABELS[name];
            option.selected = name === 'small';
            sizeSelect.appendChild(option);
        });

        sizeField.appendChild(sizeLabelEl);
        sizeField.appendChild(sizeSelect);

        var linkField = document.createElement('p');
        linkField.className = 'lp-field';
        var linkLabelEl = document.createElement('label');
        linkLabelEl.textContent = 'Link To';
        var linkSelect = document.createElement('select');

        [['none', 'None'], ['file', 'Media File']].forEach(function (pair) {
            var option = document.createElement('option');
            option.value = pair[0];
            option.textContent = pair[1];
            linkSelect.appendChild(option);
        });

        linkField.appendChild(linkLabelEl);
        linkField.appendChild(linkSelect);

        var actions = document.createElement('div');
        actions.className = 'lp-editor-media-dialog__settings-actions';

        var insertButton = document.createElement('button');
        insertButton.type = 'button';
        insertButton.className = 'lp-button lp-button--primary';
        insertButton.textContent = 'Insert';
        insertButton.disabled = folders.length === 0;
        insertButton.addEventListener('click', function () {
            onInsert({ folderId: parseInt(folderSelect.value, 10) || 0, link: linkSelect.value, size: sizeSelect.value });
            dialog.close();
        });

        var cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className = 'lp-button';
        cancelButton.textContent = 'Cancel';
        cancelButton.addEventListener('click', function () { dialog.close(); });

        actions.appendChild(insertButton);
        actions.appendChild(cancelButton);

        dialog.appendChild(heading);

        if (folders.length === 0) {
            var status = document.createElement('p');
            status.className = 'lp-editor-media-dialog__status';
            status.textContent = 'No Media folders exist yet.';
            dialog.appendChild(status);
        } else {
            dialog.appendChild(folderField);
            dialog.appendChild(sizeField);
            dialog.appendChild(linkField);
        }

        dialog.appendChild(actions);
        dialog.addEventListener('close', function () { dialog.remove(); });
        document.body.appendChild(dialog);
        dialog.showModal();
    }

    /**
     * LP-150: "Insert Audio"/"Insert Video" — the same lightweight,
     * preloaded-list shape as openFolderGalleryPicker() above (an
     * audio/video library is typically small enough that a paginated
     * grid query, like Insert Image's, isn't worth the extra request).
     * $type is 'audio' or 'video', selecting which of
     * data-media-audio/data-media-video to list. onInsert receives
     * {id}.
     */
    function openMediaPlayerPicker(container, type, onInsert) {
        var items = JSON.parse(container.dataset['media' + (type === 'audio' ? 'Audio' : 'Video')] || '[]');

        var dialog = document.createElement('dialog');
        dialog.className = 'lp-editor-media-dialog';

        var heading = document.createElement('h2');
        heading.textContent = type === 'audio' ? 'Insert Audio' : 'Insert Video';
        heading.className = 'lp-editor-media-dialog__heading';

        var fileField = document.createElement('p');
        fileField.className = 'lp-field';
        var fileLabelEl = document.createElement('label');
        fileLabelEl.textContent = type === 'audio' ? 'Audio File' : 'Video File';
        var fileSelect = document.createElement('select');

        items.forEach(function (item) {
            var option = document.createElement('option');
            option.value = String(item.id);
            option.textContent = item.name;
            fileSelect.appendChild(option);
        });

        fileField.appendChild(fileLabelEl);
        fileField.appendChild(fileSelect);

        var actions = document.createElement('div');
        actions.className = 'lp-editor-media-dialog__settings-actions';

        var insertButton = document.createElement('button');
        insertButton.type = 'button';
        insertButton.className = 'lp-button lp-button--primary';
        insertButton.textContent = 'Insert';
        insertButton.disabled = items.length === 0;
        insertButton.addEventListener('click', function () {
            onInsert({ id: parseInt(fileSelect.value, 10) || 0 });
            dialog.close();
        });

        var cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className = 'lp-button';
        cancelButton.textContent = 'Cancel';
        cancelButton.addEventListener('click', function () { dialog.close(); });

        actions.appendChild(insertButton);
        actions.appendChild(cancelButton);

        dialog.appendChild(heading);

        if (items.length === 0) {
            var status = document.createElement('p');
            status.className = 'lp-editor-media-dialog__status';
            status.textContent = type === 'audio'
                ? 'No audio files in the Media Manager yet.'
                : 'No video files in the Media Manager yet.';
            dialog.appendChild(status);
        } else {
            dialog.appendChild(fileField);
        }

        dialog.appendChild(actions);
        dialog.addEventListener('close', function () { dialog.remove(); });
        document.body.appendChild(dialog);
        dialog.showModal();
    }

    // ------------------------------------------------------------------
    // Link picker (LP-130) — the WYSIWYG editor's own "Insert/Edit Link"
    // dialog, replacing TinyMCE's native link plugin dialog entirely (see
    // basePlugins above) so a link can target an existing Post or Page
    // from a live, searchable list instead of requiring its permalink to
    // be copied in from another tab by hand. Queries its own
    // link_picker_query sub-action (same data-upload-url every other
    // sub-action already posts to), returning up to 20 recently
    // published/modified Posts and Pages, newest first, title-filtered
    // server-side as the admin types. No pagination/Load More — this is
    // meant to surface a short, obvious shortlist, not browse the whole
    // site; a specific, older item is still reachable by typing more of
    // its title into Search.
    //
    // LP-168: also opens for an image selection, reachable via the
    // floating quickbar TinyMCE shows when an image is clicked (see
    // quickbars_image_toolbar below) as well as the main toolbar button.
    // "Link Text" makes no sense for an image, so it's hidden, and
    // Add/Update/Remove wrap or unwrap the actual <img> element in place
    // rather than going through insertContent() with an escaped text
    // label, which would silently replace the image with plain text.
    // ------------------------------------------------------------------

    function openLinkPicker(container, editor) {
        var pickerUrl = container.dataset.uploadUrl;
        var pickerCsrf = container.dataset.linkPickerCsrf;

        // LP-168: the selected node is a control selection (an <img>)
        // rather than a text range when the admin clicked an image —
        // "Link Text" makes no sense there, and Apply/Remove below must
        // wrap/unwrap the image element itself rather than replacing the
        // selection with escaped label text (which would silently
        // destroy the image).
        var selectedNode = editor.selection.getNode();
        var isImageSelection = selectedNode.nodeName === 'IMG';

        // Editing an existing link puts the dialog in "Update" mode —
        // its href/text/target seed the fields below, and Update/Remove
        // Link act on this same <a> element in place rather than
        // inserting a new one.
        var existingAnchor = editor.dom.getParent(selectedNode, 'A');

        var dialog = document.createElement('dialog');
        dialog.className = 'lp-editor-link-dialog';

        var heading = document.createElement('h2');
        heading.className = 'lp-editor-media-dialog__heading';
        heading.textContent = isImageSelection
            ? (existingAnchor ? 'Edit Image Link' : 'Add Link to Image')
            : (existingAnchor ? 'Edit Link' : 'Insert Link');

        var urlField = document.createElement('p');
        urlField.className = 'lp-field';
        var urlLabel = document.createElement('label');
        urlLabel.textContent = 'URL';
        var urlInput = document.createElement('input');
        urlInput.type = 'text';
        urlInput.value = existingAnchor ? (existingAnchor.getAttribute('href') || '') : '';
        urlInput.placeholder = 'https://…';
        urlField.appendChild(urlLabel);
        urlField.appendChild(urlInput);

        // Kept even for an image selection (some code paths below read
        // its .value unconditionally) — it's simply never appended to
        // the dialog in that case, since there's no text to label an image.
        var textField = document.createElement('p');
        textField.className = 'lp-field';
        var textLabel = document.createElement('label');
        textLabel.textContent = 'Link Text';
        var textInput = document.createElement('input');
        textInput.type = 'text';
        textInput.value = existingAnchor ? existingAnchor.textContent : editor.selection.getContent({ format: 'text' });
        textField.appendChild(textLabel);
        textField.appendChild(textInput);

        var newTabLabel = document.createElement('label');
        newTabLabel.className = 'lp-field--checkbox';
        var newTabCheckbox = document.createElement('input');
        newTabCheckbox.type = 'checkbox';
        newTabCheckbox.checked = !!existingAnchor && existingAnchor.getAttribute('target') === '_blank';
        newTabLabel.appendChild(newTabCheckbox);
        newTabLabel.appendChild(document.createTextNode(' Open link in a new tab'));

        var existingHeading = document.createElement('h3');
        existingHeading.className = 'lp-editor-link-dialog__subheading';
        existingHeading.textContent = 'Or link to existing content';

        var searchInput = document.createElement('input');
        searchInput.type = 'search';
        searchInput.className = 'lp-editor-media-dialog__search';
        searchInput.placeholder = 'Search posts and pages…';
        searchInput.setAttribute('aria-label', 'Search posts and pages');

        var status = document.createElement('p');
        status.className = 'lp-editor-media-dialog__status';
        status.hidden = true;

        var results = document.createElement('ul');
        results.className = 'lp-editor-link-dialog__results';

        var state = { term: '', requestId: 0 };

        function renderItem(item) {
            var row = document.createElement('li');
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'lp-editor-link-dialog__result';

            var titleEl = document.createElement('span');
            titleEl.className = 'lp-editor-link-dialog__result-title';
            titleEl.textContent = item.title;

            var metaEl = document.createElement('span');
            metaEl.className = 'lp-editor-link-dialog__result-meta';
            metaEl.textContent = item.type + ' · ' + item.date;

            button.appendChild(titleEl);
            button.appendChild(metaEl);
            button.addEventListener('click', function () {
                urlInput.value = item.url;

                // Matches manually pasting a permalink in — it only ever
                // fills the visible label when the admin hasn't already
                // typed one, never overwrites a Link Text they set.
                if (textInput.value.trim() === '') {
                    textInput.value = item.title;
                }
            });

            row.appendChild(button);
            results.appendChild(row);
        }

        function fetchResults() {
            var requestId = ++state.requestId;

            status.textContent = 'Loading…';
            status.hidden = false;
            results.innerHTML = '';

            var formData = new FormData();
            formData.append('form', 'link_picker_query');
            formData.append('csrf_token', pickerCsrf);
            formData.append('term', state.term);

            fetch(pickerUrl, { method: 'POST', body: formData })
                .then(function (response) { return response.json(); })
                .then(function (json) {
                    // Csrf::verify() is single-use — see openMediaPicker()'s
                    // identical comment for why every response must hand
                    // back a fresh token.
                    if (json.csrfToken) {
                        pickerCsrf = json.csrfToken;
                        container.dataset.linkPickerCsrf = json.csrfToken;
                    }

                    if (requestId !== state.requestId) {
                        return;
                    }

                    var items = json.items || [];
                    items.forEach(renderItem);

                    if (items.length === 0) {
                        status.textContent = state.term !== '' ? 'No matching posts or pages.' : 'No posts or pages yet.';
                        status.hidden = false;
                    } else {
                        status.hidden = true;
                    }
                })
                .catch(function () {
                    status.textContent = 'Could not load results. Try again.';
                    status.hidden = false;
                });
        }

        var searchTimer = null;
        searchInput.addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                state.term = searchInput.value.trim();
                fetchResults();
            }, 300);
        });

        var actions = document.createElement('div');
        actions.className = 'lp-editor-media-dialog__settings-actions';

        var applyButton = document.createElement('button');
        applyButton.type = 'button';
        applyButton.className = 'lp-button lp-button--primary';
        applyButton.textContent = existingAnchor ? 'Update' : 'Add Link';
        applyButton.addEventListener('click', function () {
            var url = urlInput.value.trim();

            if (url === '') {
                urlInput.focus();

                return;
            }

            // Direct DOM edits (the existingAnchor/isImageSelection
            // branches) bypass insertContent()'s own undo-level/change-
            // event handling, so they're wrapped in a transaction — the
            // same "change" event this fires is what
            // editor.on('change keyup', ...) below listens for to keep
            // the real <textarea> form field synced.
            if (isImageSelection) {
                // LP-168: wraps/rewraps the actual <img> element rather
                // than going through insertContent() with a text label —
                // insertContent() would replace the image's control
                // selection with the given HTML string outright, losing
                // the image entirely.
                editor.undoManager.transact(function () {
                    var anchor = existingAnchor;

                    if (!anchor) {
                        anchor = editor.dom.create('a');
                        selectedNode.parentNode.insertBefore(anchor, selectedNode);
                        anchor.appendChild(selectedNode);
                    }

                    // editor.dom.setAttrib(), not the plain DOM setAttribute() — TinyMCE's
                    // HTML parser tags every parsed href with a shadow `data-mce-href`
                    // attribute it treats as the source of truth on output. setAttribute()
                    // updates the visible href (so the change looks applied on screen) but
                    // leaves that shadow attribute pointing at the old URL, and
                    // editor.getContent() serializes from the shadow attribute — so the
                    // saved/submitted content silently reverts to the old link. dom.setAttrib()
                    // keeps both in sync.
                    editor.dom.setAttrib(anchor, 'href', url);

                    if (newTabCheckbox.checked) {
                        anchor.setAttribute('target', '_blank');
                    } else {
                        anchor.removeAttribute('target');
                        anchor.removeAttribute('rel');
                    }
                });
                editor.selection.select(selectedNode);
            } else {
                var text = textInput.value.trim() || url;

                if (existingAnchor) {
                    editor.undoManager.transact(function () {
                        // See the isImageSelection branch above for why dom.setAttrib() and
                        // not setAttribute() — same shadow-attribute gotcha applies here too.
                        editor.dom.setAttrib(existingAnchor, 'href', url);
                        existingAnchor.textContent = text;

                        if (newTabCheckbox.checked) {
                            existingAnchor.setAttribute('target', '_blank');
                        } else {
                            existingAnchor.removeAttribute('target');
                            existingAnchor.removeAttribute('rel');
                        }
                    });
                    editor.selection.select(existingAnchor);
                } else {
                    var targetAttr = newTabCheckbox.checked ? ' target="_blank"' : '';
                    editor.insertContent('<a href="' + escapeHtmlAttr(url) + '"' + targetAttr + '>' + escapeHtmlAttr(text) + '</a>');
                }
            }

            dialog.close();
        });

        var actionButtons = [applyButton];

        if (existingAnchor) {
            var removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'lp-button lp-button--link lp-button--link--danger';
            removeButton.textContent = 'Remove Link';
            removeButton.addEventListener('click', function () {
                editor.undoManager.transact(function () {
                    // true = unwrap, keeping the anchor's own text/inline
                    // markup in place rather than deleting it outright.
                    editor.dom.remove(existingAnchor, true);
                });
                dialog.close();
            });
            actionButtons.push(removeButton);
        }

        var cancelButton = document.createElement('button');
        cancelButton.type = 'button';
        cancelButton.className = 'lp-button';
        cancelButton.textContent = 'Cancel';
        cancelButton.addEventListener('click', function () { dialog.close(); });
        actionButtons.push(cancelButton);

        actionButtons.forEach(function (button) { actions.appendChild(button); });

        dialog.appendChild(heading);
        dialog.appendChild(urlField);

        if (!isImageSelection) {
            dialog.appendChild(textField);
        }

        dialog.appendChild(newTabLabel);
        dialog.appendChild(existingHeading);
        dialog.appendChild(searchInput);
        dialog.appendChild(status);
        dialog.appendChild(results);
        dialog.appendChild(actions);
        dialog.addEventListener('close', function () { dialog.remove(); });
        document.body.appendChild(dialog);
        dialog.showModal();

        fetchResults();
    }

    // ------------------------------------------------------------------
    // Icon picker (LPP-002) — shared by both editors, mirroring
    // openMediaPicker()'s <dialog> + search + paginated-grid + Load More
    // shape, queried against the Font Awesome plugin's own
    // font_awesome_icon_query sub-action (same data-upload-url every
    // other sub-action already posts to). Only wired up at all when
    // data-icon-picker-csrf is present — the container-building views
    // only render that attribute while the plugin is enabled (see
    // lp_fontawesome_enabled() in posts/new.php / pages/new.php /
    // downloads/add-new.php), so there's nothing to search when it's
    // off. data-icon-picker-css (a JSON array of the plugin's own
    // configured CDN/self-hosted URL(s) — see the lp_fontawesome_css_urls
    // filter in font-awesome.php) is lazy-loaded here, on first open,
    // rather than unconditionally on every editor page load, so real
    // icon glyphs render in the grid without paying for Font Awesome's
    // CSS on every post/page edit screen regardless of whether this
    // picker is ever opened.
    // ------------------------------------------------------------------

    function openIconPicker(container, onInsert) {
        var pickerUrl = container.dataset.uploadUrl;
        var pickerCsrf = container.dataset.iconPickerCsrf;

        JSON.parse(container.dataset.iconPickerCss || '[]').forEach(loadStyle);

        var dialog = document.createElement('dialog');
        dialog.className = 'lp-editor-media-dialog lp-editor-icon-dialog';

        var heading = document.createElement('h2');
        heading.textContent = 'Insert Icon';
        heading.className = 'lp-editor-media-dialog__heading';

        var searchInput = document.createElement('input');
        searchInput.type = 'search';
        searchInput.className = 'lp-editor-media-dialog__search';
        searchInput.placeholder = 'Search icons…';
        searchInput.setAttribute('aria-label', 'Search icons');

        var status = document.createElement('p');
        status.className = 'lp-editor-media-dialog__status';
        status.hidden = true;

        var grid = document.createElement('div');
        grid.className = 'lp-editor-media-dialog__grid lp-editor-icon-dialog__grid';

        var loadMoreButton = document.createElement('button');
        loadMoreButton.type = 'button';
        loadMoreButton.className = 'lp-button lp-editor-media-dialog__load-more';
        loadMoreButton.textContent = 'Load More';
        loadMoreButton.hidden = true;

        var state = { term: '', page: 1, loaded: 0, total: 0, requestId: 0 };

        function renderItem(icon) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'lp-editor-icon-dialog__item';
            button.title = icon.name;

            var glyph = document.createElement('i');
            glyph.className = 'fa-' + icon.style + ' fa-' + icon.name;
            glyph.setAttribute('aria-hidden', 'true');

            var label = document.createElement('span');
            label.textContent = icon.label;

            button.appendChild(glyph);
            button.appendChild(label);
            button.addEventListener('click', function () {
                onInsert({ name: icon.name, style: icon.style });
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
            formData.append('form', 'font_awesome_icon_query');
            formData.append('csrf_token', pickerCsrf);
            formData.append('term', state.term);
            formData.append('page', String(state.page));

            fetch(pickerUrl, { method: 'POST', body: formData })
                .then(function (response) { return response.json(); })
                .then(function (json) {
                    // Csrf::verify() is single-use — see openMediaPicker()'s
                    // identical comment for why every response must hand
                    // back a fresh token.
                    if (json.csrfToken) {
                        pickerCsrf = json.csrfToken;
                        container.dataset.iconPickerCsrf = json.csrfToken;
                    }

                    if (requestId !== state.requestId) {
                        return;
                    }

                    var items = json.items || [];
                    items.forEach(renderItem);
                    state.loaded += items.length;
                    state.total = json.total || 0;

                    if (state.loaded === 0) {
                        status.textContent = state.term !== '' ? 'No icons match your search.' : 'No icons available.';
                        status.hidden = false;
                    } else {
                        status.hidden = true;
                    }

                    loadMoreButton.hidden = state.loaded >= state.total;
                })
                .catch(function () {
                    status.textContent = 'Could not load icons. Try again.';
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

        loadMoreButton.addEventListener('click', function () {
            state.page += 1;
            fetchPage(false);
        });

        var closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'lp-button lp-editor-media-dialog__close';
        closeButton.textContent = 'Cancel';
        closeButton.addEventListener('click', function () { dialog.close(); });

        dialog.appendChild(heading);
        dialog.appendChild(searchInput);
        dialog.appendChild(status);
        dialog.appendChild(grid);
        dialog.appendChild(loadMoreButton);
        dialog.appendChild(closeButton);
        dialog.addEventListener('close', function () { dialog.remove(); });
        document.body.appendChild(dialog);
        dialog.showModal();

        fetchPage(true);
    }

    // ------------------------------------------------------------------
    // Emoji picker (LPP-006) — shared by both editors, and by any
    // standalone lp_emoji_picker_button() trigger a theme renders
    // outside the default toolbars (see wireStandaloneEmojiTriggers()
    // below). Unlike openIconPicker() above, the whole dataset is
    // already embedded in data-emoji-dataset (small enough — see the
    // plugin's own Performance notes — to search/browse entirely
    // client-side, no per-keystroke round trip). The only request this
    // ever makes is a fire-and-forget "record this in Recently Used"
    // POST after an insert, which never blocks the insert itself.
    // Deliberately does NOT close on insert (unlike every other picker
    // in this file) — the ticket wants repeated inserts in one session;
    // it closes only via Escape (native <dialog> behavior) or Cancel.
    // ------------------------------------------------------------------

    function openEmojiPicker(container, onInsert) {
        var dataset = JSON.parse(container.dataset.emojiDataset || '[]');
        var recent = JSON.parse(container.dataset.emojiRecent || '[]');
        var recordUrl = container.dataset.uploadUrl || '';
        var recordCsrf = container.dataset.emojiRecordCsrf || '';
        var byEmoji = {};
        dataset.forEach(function (item) { byEmoji[item.emoji] = item; });

        var categories = [];
        dataset.forEach(function (item) {
            if (categories.indexOf(item.category) === -1) {
                categories.push(item.category);
            }
        });

        var RECENT_LABEL = 'Recently Used';
        var state = {
            term: '',
            category: recent.length > 0 ? RECENT_LABEL : (container.dataset.emojiDefaultCategory || categories[0] || ''),
        };

        var dialog = document.createElement('dialog');
        dialog.className = 'lp-editor-media-dialog lp-editor-emoji-dialog';

        var heading = document.createElement('h2');
        heading.textContent = 'Insert Emoji';
        heading.className = 'lp-editor-media-dialog__heading';

        var searchInput = document.createElement('input');
        searchInput.type = 'search';
        searchInput.className = 'lp-editor-media-dialog__search';
        searchInput.placeholder = 'Search emoji…';
        searchInput.setAttribute('aria-label', 'Search emoji');

        var categoryNav = document.createElement('div');
        categoryNav.className = 'lp-editor-emoji-dialog__categories';
        categoryNav.setAttribute('role', 'tablist');
        categoryNav.setAttribute('aria-label', 'Emoji categories');

        var status = document.createElement('p');
        status.className = 'lp-editor-media-dialog__status';
        status.hidden = true;

        var grid = document.createElement('div');
        grid.className = 'lp-editor-media-dialog__grid lp-editor-emoji-dialog__grid';
        grid.setAttribute('role', 'group');
        grid.setAttribute('aria-label', 'Emoji');

        var closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'lp-button lp-editor-media-dialog__close';
        closeButton.textContent = 'Cancel';
        closeButton.addEventListener('click', function () { dialog.close(); });

        var categoryList = recent.length > 0 ? [RECENT_LABEL].concat(categories) : categories;

        function renderCategoryNav() {
            categoryNav.innerHTML = '';
            categoryList.forEach(function (category) {
                var tab = document.createElement('button');
                tab.type = 'button';
                tab.className = 'lp-editor-emoji-dialog__category' + (state.category === category && state.term === '' ? ' is-active' : '');
                tab.textContent = category;
                tab.setAttribute('role', 'tab');
                tab.setAttribute('aria-selected', state.category === category && state.term === '' ? 'true' : 'false');
                tab.addEventListener('click', function () {
                    state.term = '';
                    searchInput.value = '';
                    state.category = category;
                    renderCategoryNav();
                    renderGrid();
                });
                categoryNav.appendChild(tab);
            });
        }

        function itemsForState() {
            if (state.term !== '') {
                var term = state.term.toLowerCase();

                return dataset.filter(function (item) {
                    if (item.name.toLowerCase().indexOf(term) !== -1) {
                        return true;
                    }

                    return item.keywords.some(function (keyword) {
                        return keyword.toLowerCase().indexOf(term) !== -1;
                    });
                });
            }

            if (state.category === RECENT_LABEL) {
                return recent.map(function (emoji) { return byEmoji[emoji]; }).filter(Boolean);
            }

            return dataset.filter(function (item) { return item.category === state.category; });
        }

        function recordRecent(emoji) {
            // Move-to-front locally so the Recently Used tab reflects the
            // insert immediately, without waiting on the network — the
            // AJAX response below is the source of truth for the actual
            // server-enforced limit, and overwrites this once it arrives.
            recent = [emoji].concat(recent.filter(function (existing) { return existing !== emoji; }));
            container.dataset.emojiRecent = JSON.stringify(recent);

            if (categoryList.indexOf(RECENT_LABEL) === -1) {
                categoryList.unshift(RECENT_LABEL);
                renderCategoryNav();
            }

            if (!recordUrl || !recordCsrf) {
                return;
            }

            var formData = new FormData();
            formData.append('form', 'emoji_picker_record_recent');
            formData.append('csrf_token', recordCsrf);
            formData.append('emoji', emoji);

            fetch(recordUrl, { method: 'POST', body: formData })
                .then(function (response) { return response.json(); })
                .then(function (json) {
                    if (json.csrfToken) {
                        recordCsrf = json.csrfToken;
                        container.dataset.emojiRecordCsrf = json.csrfToken;
                    }

                    if (Array.isArray(json.recent)) {
                        recent = json.recent;
                        container.dataset.emojiRecent = JSON.stringify(recent);

                        if (state.category === RECENT_LABEL) {
                            renderGrid();
                        }
                    }
                })
                .catch(function () {
                    // Best-effort — the emoji was already inserted regardless
                    // of whether "recently used" persisted server-side.
                });
        }

        function renderItem(item) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'lp-editor-emoji-dialog__item';
            button.textContent = item.emoji;
            button.setAttribute('aria-label', item.name);
            button.title = item.name;
            button.addEventListener('click', function () {
                onInsert(item.emoji);
                recordRecent(item.emoji);
            });
            grid.appendChild(button);
        }

        function renderGrid() {
            grid.innerHTML = '';
            var items = itemsForState();

            if (items.length === 0) {
                status.textContent = state.term !== '' ? 'No emoji match your search.' : 'Nothing here yet.';
                status.hidden = false;

                return;
            }

            status.hidden = true;
            items.forEach(renderItem);
        }

        // Roving arrow-key navigation across the grid — computed from the
        // grid's own actual column count (grid-template-columns resolves
        // to a fixed number of tracks at layout time even though the CSS
        // itself uses auto-fill) rather than a hardcoded number, so this
        // keeps working if the tile size/dialog width ever changes.
        grid.addEventListener('keydown', function (event) {
            if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown' && event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
                return;
            }

            var items = Array.prototype.slice.call(grid.children);
            var index = items.indexOf(document.activeElement);

            if (index === -1) {
                return;
            }

            var columns = window.getComputedStyle(grid).gridTemplateColumns.split(' ').length || 1;
            var target = index;

            if (event.key === 'ArrowLeft') {
                target = index - 1;
            } else if (event.key === 'ArrowRight') {
                target = index + 1;
            } else if (event.key === 'ArrowUp') {
                target = index - columns;
            } else if (event.key === 'ArrowDown') {
                target = index + columns;
            }

            if (target >= 0 && target < items.length) {
                event.preventDefault();
                items[target].focus();
            }
        });

        var searchTimer = null;
        searchInput.addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                state.term = searchInput.value.trim();
                renderCategoryNav();
                renderGrid();
            }, 150);
        });

        dialog.appendChild(heading);
        dialog.appendChild(searchInput);
        dialog.appendChild(categoryNav);
        dialog.appendChild(status);
        dialog.appendChild(grid);
        dialog.appendChild(closeButton);
        dialog.addEventListener('close', function () { dialog.remove(); });
        document.body.appendChild(dialog);
        dialog.showModal();

        renderCategoryNav();
        renderGrid();
        searchInput.focus();
    }

    // ------------------------------------------------------------------
    // Standalone lp_emoji_picker_button() triggers (LPP-006) — a page
    // can render one of these anywhere (e.g. a theme's comment form),
    // outside the default Post/Page/Downloads editor toolbars this file
    // otherwise assumes. Inserts into data-emoji-target (a CSS selector)
    // if given, else the nearest <textarea> in the same <form>.
    // ------------------------------------------------------------------

    function wireStandaloneEmojiTriggers() {
        document.querySelectorAll('[data-lp-emoji-picker-trigger]').forEach(function (button) {
            button.addEventListener('click', function () {
                var targetSelector = button.dataset.emojiTarget;
                var target = targetSelector
                    ? document.querySelector(targetSelector)
                    : (button.closest('form') || document).querySelector('textarea');

                if (!target) {
                    return;
                }

                openEmojiPicker(button, function (emoji) {
                    var start = target.selectionStart || target.value.length;
                    var end = target.selectionEnd || target.value.length;
                    target.value = target.value.slice(0, start) + emoji + target.value.slice(end);
                    var caret = start + emoji.length;
                    target.setSelectionRange(caret, caret);
                    target.focus();
                });
            });
        });
    }

    // ------------------------------------------------------------------
    // Shortcode picker (LP-110) — shared by both editors. Reads the
    // registered-shortcode metadata core built server-side
    // (ShortcodeManager::toArray(), via register_shortcode() —
    // font-awesome.php/wordpress-importer.php/downloads.php each
    // register one) from data-shortcodes, no AJAX query of its own
    // needed since that list is small and known up front. A two-step
    // dialog: pick which shortcode, then fill in a form built from its
    // own field list, mirroring openMediaPicker()'s grid-then-settings
    // shape above.
    // ------------------------------------------------------------------

    function openShortcodePicker(container, onInsert) {
        var shortcodes = JSON.parse(container.dataset.shortcodes || '{}');
        var names = Object.keys(shortcodes);

        if (names.length === 0) {
            return;
        }

        var dialog = document.createElement('dialog');
        dialog.className = 'lp-editor-media-dialog lp-editor-shortcode-dialog';

        var heading = document.createElement('h2');
        heading.className = 'lp-editor-media-dialog__heading';
        heading.textContent = 'Insert Shortcode';

        var list = document.createElement('div');
        list.className = 'lp-editor-shortcode-dialog__list';

        var form = document.createElement('div');
        form.hidden = true;

        function showListStep() {
            list.hidden = false;
            form.hidden = true;
            form.innerHTML = '';
        }

        function showFormStep(name) {
            list.hidden = true;
            form.hidden = false;
            form.innerHTML = '';

            var definition = shortcodes[name];

            var formHeading = document.createElement('h3');
            formHeading.className = 'lp-editor-shortcode-dialog__form-heading';
            formHeading.textContent = definition.label;
            form.appendChild(formHeading);

            // {field, input} per rendered field — read back when Insert
            // is clicked, and searched by name so an Icon field can
            // auto-fill a sibling 'style' field (see the 'icon' branch
            // below).
            var fieldEntries = [];

            definition.fields.forEach(function (field) {
                var wrapper = document.createElement('p');
                wrapper.className = 'lp-field';
                var input;

                if (field.type === 'checkbox') {
                    var checkboxLabel = document.createElement('label');
                    checkboxLabel.className = 'lp-field--checkbox';
                    input = document.createElement('input');
                    input.type = 'checkbox';
                    input.checked = field.default === '1';
                    checkboxLabel.appendChild(input);
                    checkboxLabel.appendChild(document.createTextNode(' ' + field.label));
                    wrapper.className = '';
                    wrapper.appendChild(checkboxLabel);
                } else {
                    var fieldLabel = document.createElement('label');
                    fieldLabel.textContent = field.label + (field.required ? ' *' : '');
                    wrapper.appendChild(fieldLabel);

                    if (field.type === 'select') {
                        input = document.createElement('select');
                        Object.keys(field.choices || {}).forEach(function (value) {
                            var option = document.createElement('option');
                            option.value = value;
                            option.textContent = field.choices[value];
                            option.selected = value === field.default;
                            input.appendChild(option);
                        });
                        wrapper.appendChild(input);
                    } else if (field.type === 'icon') {
                        input = document.createElement('input');
                        input.type = 'hidden';
                        input.value = field.default || '';

                        var chosenLabel = document.createElement('span');
                        chosenLabel.className = 'lp-editor-shortcode-dialog__icon-chosen';

                        var chooseButton = document.createElement('button');
                        chooseButton.type = 'button';
                        chooseButton.className = 'lp-button lp-button--secondary';
                        chooseButton.textContent = 'Choose Icon…';
                        chooseButton.addEventListener('click', function () {
                            openIconPicker(container, function (payload) {
                                input.value = payload.name;
                                chosenLabel.textContent = payload.name;

                                // The icon browser already knows which
                                // style the chosen icon actually has —
                                // no reason to make the admin pick it
                                // again separately if this shortcode also
                                // registered a 'style' field.
                                var styleEntry = fieldEntries.filter(function (entry) {
                                    return entry.field.name === 'style';
                                })[0];

                                if (styleEntry) {
                                    styleEntry.input.value = payload.style;
                                }
                            });
                        });

                        wrapper.appendChild(chooseButton);
                        wrapper.appendChild(chosenLabel);
                        wrapper.appendChild(input);
                    } else {
                        input = document.createElement('input');
                        input.type = field.type === 'number' ? 'number' : 'text';
                        input.value = field.default || '';
                        wrapper.appendChild(input);
                    }
                }

                if (field.help) {
                    var hint = document.createElement('span');
                    hint.className = 'lp-field__hint';
                    hint.textContent = field.help;
                    wrapper.appendChild(hint);
                }

                form.appendChild(wrapper);
                fieldEntries.push({ field: field, input: input });
            });

            var actions = document.createElement('div');
            actions.className = 'lp-editor-media-dialog__settings-actions';

            var insertButton = document.createElement('button');
            insertButton.type = 'button';
            insertButton.className = 'lp-button lp-button--primary';
            insertButton.textContent = 'Insert';
            insertButton.addEventListener('click', function () {
                for (var i = 0; i < fieldEntries.length; i++) {
                    var entry = fieldEntries[i];

                    if (entry.field.required && entry.field.type !== 'checkbox' && entry.input.value.trim() === '') {
                        entry.input.focus();

                        return;
                    }
                }

                var attrs = '';

                fieldEntries.forEach(function (entry) {
                    var value = entry.field.type === 'checkbox'
                        ? (entry.input.checked ? '1' : '0')
                        : entry.input.value;

                    // Omitted rather than spelled out at its default —
                    // an admin who never touches a field gets the same
                    // minimal shortcode text they'd have typed by hand.
                    // A checkbox with no explicit default (an empty
                    // string, same as every other field type) still
                    // means "unchecked", not "always include" — a
                    // registering plugin shouldn't have to know to pass
                    // default: '0' just to get the same omission every
                    // other field type gets for free.
                    var defaultValue = entry.field.type === 'checkbox' ? (entry.field.default || '0') : (entry.field.default || '');

                    if (value === '' || value === defaultValue) {
                        return;
                    }

                    attrs += ' ' + entry.field.name + '="' + shortcodeAttrValue(value) + '"';
                });

                onInsert('[' + name + attrs + ']');
                dialog.close();
            });

            var backButton = document.createElement('button');
            backButton.type = 'button';
            backButton.className = 'lp-button';
            backButton.textContent = 'Back';
            backButton.addEventListener('click', showListStep);

            actions.appendChild(insertButton);
            actions.appendChild(backButton);
            form.appendChild(actions);
        }

        names.forEach(function (name) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'lp-editor-shortcode-dialog__item';
            button.textContent = shortcodes[name].label;
            button.addEventListener('click', function () { showFormStep(name); });
            list.appendChild(button);
        });

        var closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'lp-button lp-editor-media-dialog__close';
        closeButton.textContent = 'Cancel';
        closeButton.addEventListener('click', function () { dialog.close(); });

        dialog.appendChild(heading);
        dialog.appendChild(list);
        dialog.appendChild(form);
        dialog.appendChild(closeButton);
        dialog.addEventListener('close', function () { dialog.remove(); });
        document.body.appendChild(dialog);
        dialog.showModal();
    }

    // ------------------------------------------------------------------
    // Upload helper — shared by both editors.
    // ------------------------------------------------------------------

    function uploadFile(container, file) {
        var formData = new FormData();
        formData.append('form', 'editor_upload');
        formData.append('csrf_token', container.dataset.uploadCsrf);
        formData.append('file', file);

        return fetch(container.dataset.uploadUrl, { method: 'POST', body: formData })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                // Csrf::verify() is single-use (app/Core/Security/Csrf.php)
                // — every editor_upload response carries a freshly issued
                // token, which must replace the page-load one here or a
                // second upload in the same page load fails CSRF
                // verification (matches admin/assets/js/multi-upload.js's
                // identical pattern for the Media Manager).
                if (json.csrfToken) {
                    container.dataset.uploadCsrf = json.csrfToken;
                }

                if (json.error) {
                    throw new Error(json.error);
                }

                // Resolves with the full response (not just .url) so the
                // media picker's "Upload New" step (LP-115) can read
                // json.item straight into showSettingsStep() — callers
                // that only ever wanted the bare URL (TinyMCE's/EasyMDE's
                // own inline image-upload hooks below) read json.url off
                // the same object instead.
                return json;
            });
    }

    // ------------------------------------------------------------------
    // Markdown editor (EasyMDE)
    // ------------------------------------------------------------------

    /**
     * Appends a trailing {.left|center|right|justify} marker (LP-016,
     * MarkdownParser::stripAlignmentMarker()) to the current selection,
     * or the current line if nothing is selected — Markdown has no
     * attribute syntax, so this minimal, kramdown-inspired convention
     * is how a heading/paragraph's alignment survives at all. Replaces
     * any marker already trailing that text first, so re-clicking a
     * different alignment button swaps it rather than stacking markers.
     */
    function wrapSelectionWithAlignment(cm, align) {
        var hasSelection = cm.somethingSelected();
        var from = hasSelection ? cm.getCursor('from') : { line: cm.getCursor().line, ch: 0 };
        var to = hasSelection ? cm.getCursor('to') : { line: cm.getCursor().line, ch: cm.getLine(cm.getCursor().line).length };
        var text = cm.getRange(from, to);
        var stripped = text.replace(/\s*\{\.(left|center|right|justify)\}\s*$/, '');

        cm.replaceRange(stripped + ' {.' + align + '}', from, to);
    }

    /**
     * Inserts the LP-079 More tag on its own line, blank-line-separated
     * from surrounding text — the literal marker
     * ContentRenderer::splitAtMoreTag() looks for in raw Markdown/Plain
     * content. See that method's docblock for why the marker itself
     * (rather than any rendered form of it) is what gets stored.
     */
    function insertMoreTag(cm) {
        cm.replaceSelection('\n\n<!--more-->\n\n');
    }

    /**
     * Wraps the current selection in `++...++`
     * (MarkdownParser::parseUnderline()) — Markdown has no native
     * underline syntax, so this mirrors the existing `~~strikethrough~~`
     * convention with a marker CommonMark doesn't otherwise use. Falls
     * back to placeholder text when nothing is selected, the same
     * pattern EasyMDE's own bold/italic toolbar actions use.
     */
    function wrapSelectionWithUnderline(cm) {
        var selection = cm.getSelection();

        cm.replaceSelection('++' + (selection !== '' ? selection : 'underlined text') + '++');
    }

    /**
     * Wraps the current selection in `[text]{.color}`
     * (MarkdownParser::parseFontColor()) — the same trailing-marker
     * convention wrapSelectionWithAlignment() uses, extended to an inline
     * span. Falls back to placeholder text when nothing is selected.
     */
    function wrapSelectionWithColor(cm, colorName) {
        var selection = cm.getSelection();

        cm.replaceSelection('[' + (selection !== '' ? selection : 'colored text') + ']{.' + colorName + '}');
    }

    /**
     * Small swatch-grid dialog for picking a font color — shares the
     * <dialog>-based structure openMediaPicker() above uses, at a much
     * smaller scale (LP-016 parity: TinyMCE gets the same fixed palette
     * via its lumoraFontColor menu button below).
     */
    function openColorPicker(onSelect) {
        var dialog = document.createElement('dialog');
        dialog.className = 'lp-editor-color-dialog';

        var grid = document.createElement('div');
        grid.className = 'lp-editor-color-dialog__grid';

        FONT_COLORS.forEach(function (color) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'lp-editor-color-dialog__swatch';
            button.style.backgroundColor = color.swatch;
            button.title = color.label;
            button.setAttribute('aria-label', color.label);
            button.addEventListener('click', function () {
                onSelect(color.name);
                dialog.close();
            });
            grid.appendChild(button);
        });

        var closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'lp-button';
        closeButton.textContent = 'Cancel';
        closeButton.addEventListener('click', function () { dialog.close(); });

        dialog.appendChild(grid);
        dialog.appendChild(closeButton);
        dialog.addEventListener('close', function () { dialog.remove(); });
        document.body.appendChild(dialog);
        dialog.showModal();
    }

    // EasyMDE's own default key bindings (the library registers no others).
    var MARKDOWN_SHORTCUTS = [
        ['Bold', 'Ctrl+B'],
        ['Italic', 'Ctrl+I'],
        ['Insert link', 'Ctrl+K'],
        ['Insert image', 'Ctrl+Alt+I'],
        ['Smaller heading', 'Ctrl+H'],
        ['Bigger heading', 'Shift+Ctrl+H'],
        ['Quote', "Ctrl+'"],
        ['Bulleted list', 'Ctrl+L'],
        ['Numbered list', 'Ctrl+Alt+L'],
        ['Code block', 'Ctrl+Alt+C'],
        ['Clean block formatting', 'Ctrl+E'],
        ['Toggle preview', 'Ctrl+P'],
        ['Toggle side-by-side', 'F9'],
        ['Toggle full screen', 'F11'],
    ];

    function openShortcutsReference() {
        var dialog = document.createElement('dialog');
        dialog.className = 'lp-editor-shortcuts-dialog';
        dialog.setAttribute('aria-labelledby', 'lp-editor-shortcuts-title');

        var title = document.createElement('h2');
        title.id = 'lp-editor-shortcuts-title';
        title.className = 'lp-editor-shortcuts-dialog__title';
        title.textContent = 'Keyboard Shortcuts';

        var table = document.createElement('table');
        table.className = 'lp-editor-shortcuts-dialog__table';
        MARKDOWN_SHORTCUTS.forEach(function (row) {
            var tr = document.createElement('tr');
            var th = document.createElement('th');
            th.scope = 'row';
            th.textContent = row[0];
            var td = document.createElement('td');
            var kbd = document.createElement('kbd');
            kbd.textContent = row[1];
            td.appendChild(kbd);
            tr.appendChild(th);
            tr.appendChild(td);
            table.appendChild(tr);
        });

        var note = document.createElement('p');
        note.className = 'lp-field__hint';
        note.textContent = 'On a Mac, use Cmd in place of Ctrl.';

        var closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'lp-button';
        closeButton.textContent = 'Close';
        closeButton.addEventListener('click', function () { dialog.close(); });

        dialog.appendChild(title);
        dialog.appendChild(table);
        dialog.appendChild(note);
        dialog.appendChild(closeButton);
        dialog.addEventListener('close', function () { dialog.remove(); });
        document.body.appendChild(dialog);
        dialog.showModal();
    }

    // Drops hidden built-in buttons, then any separator left leading,
    // trailing or doubled up by the removals.
    function filterToolbar(toolbar, hidden) {
        var kept = toolbar.filter(function (item) {
            var name = typeof item === 'string' ? item : item.name;

            return name === '|' || hidden.indexOf(name) === -1;
        });

        return kept.filter(function (item, index) {
            if (item !== '|') {
                return true;
            }

            return index > 0 && index < kept.length - 1 && kept[index - 1] !== '|';
        });
    }

    function initMarkdownEditor(container, textarea, statsEl) {
        loadStyle(EASYMDE_CSS);
        loadStyle(FONT_AWESOME_CSS);

        return loadScript(EASYMDE_JS).then(function () {
            var EasyMDE = window.EasyMDE;
            var autosaveId = container.dataset.autosaveId || '';
            var iconPickerEnabled = !!container.dataset.iconPickerCsrf;
            var emojiPickerEnabled = container.dataset.emojiMarkdownEnabled === '1';
            var shortcodesEnabled = Object.keys(JSON.parse(container.dataset.shortcodes || '{}')).length > 0;
            var editorConfig = JSON.parse(container.dataset.editorConfig || '{}');

            // Built as its own variable (rather than inline in the options
            // object below) so the icon-picker button — only wired up when
            // the Font Awesome plugin is enabled — can be conditionally
            // pushed in, mirroring how the TinyMCE side's toolbar string
            // conditionally includes lumoraIcon. Each closure below only
            // reads editor.codemirror once actually clicked, by which
            // point `editor` (declared next) already holds the constructed
            // instance — the same deferred-reference pattern the existing
            // media-library/folder-gallery entries already rely on.
            var markdownToolbar = [
                'bold', 'italic', 'strikethrough',
                {
                    name: 'underline',
                    action: function () { wrapSelectionWithUnderline(editor.codemirror); },
                    className: 'fa fa-underline',
                    title: 'Underline',
                },
                {
                    name: 'font-color',
                    action: function () {
                        openColorPicker(function (colorName) {
                            wrapSelectionWithColor(editor.codemirror, colorName);
                        });
                    },
                    className: 'fa fa-paint-brush',
                    title: 'Font Color',
                },
                '|',
                'heading-1', 'heading-2', 'heading-3', '|',
                {
                    name: 'align-left',
                    action: function () { wrapSelectionWithAlignment(editor.codemirror, 'left'); },
                    className: 'fa fa-align-left',
                    title: 'Align Left',
                },
                {
                    name: 'align-center',
                    action: function () { wrapSelectionWithAlignment(editor.codemirror, 'center'); },
                    className: 'fa fa-align-center',
                    title: 'Align Center',
                },
                {
                    name: 'align-right',
                    action: function () { wrapSelectionWithAlignment(editor.codemirror, 'right'); },
                    className: 'fa fa-align-right',
                    title: 'Align Right',
                },
                {
                    name: 'align-justify',
                    action: function () { wrapSelectionWithAlignment(editor.codemirror, 'justify'); },
                    className: 'fa fa-align-justify',
                    title: 'Justify',
                },
                '|',
                {
                    name: 'more-tag',
                    action: function () { insertMoreTag(editor.codemirror); },
                    className: 'fa fa-scissors',
                    title: 'Insert Read More Tag',
                },
                '|',
                'code', 'quote', 'unordered-list', 'ordered-list', '|',
                'link',
                {
                    name: 'media-library',
                    action: function () {
                        openMediaPicker(container, function (payload) {
                            var cm = editor.codemirror;
                            // The chosen display size is which file's URL gets
                            // inserted; alignment, "no link" ({.no-lightbox}) and
                            // an explicit width/height are trailing {…} markers —
                            // see buildMarkdownImage().
                            var inserted = buildMarkdownImage(payload);
                            cm.replaceSelection(payload.caption ? '\n\n' + inserted + '\n\n' : inserted);
                        });
                    },
                    className: 'fa fa-photo',
                    title: 'Insert Image',
                },
                {
                    name: 'edit-image',
                    action: function () { openMarkdownImageEditor(editor.codemirror); },
                    className: 'fa fa-pencil-square-o',
                    title: 'Edit Image (cursor inside an image)',
                },
                {
                    name: 'folder-gallery',
                    action: function () {
                        openFolderGalleryPicker(container, function (payload) {
                            var cm = editor.codemirror;
                            var link = payload.link === 'file' ? 'full' : 'none';
                            cm.replaceSelection('[lumora_folder_gallery folder_id="' + payload.folderId + '" size="' + payload.size + '" link="' + link + '"]');
                        });
                    },
                    className: 'fa fa-th',
                    title: 'Insert Folder',
                },
                {
                    name: 'insert-audio',
                    action: function () {
                        openMediaPlayerPicker(container, 'audio', function (payload) {
                            editor.codemirror.replaceSelection('[lumora_audio id="' + payload.id + '"]');
                        });
                    },
                    className: 'fa fa-file-audio-o',
                    title: 'Insert Audio',
                },
                {
                    name: 'insert-video',
                    action: function () {
                        openMediaPlayerPicker(container, 'video', function (payload) {
                            editor.codemirror.replaceSelection('[lumora_video id="' + payload.id + '"]');
                        });
                    },
                    className: 'fa fa-file-video-o',
                    title: 'Insert Video',
                },
            ];

            if (iconPickerEnabled) {
                markdownToolbar.push({
                    name: 'icon-picker',
                    action: function () {
                        openIconPicker(container, function (payload) {
                            var cm = editor.codemirror;
                            var styleAttr = payload.style !== 'solid' ? ' style="' + payload.style + '"' : '';
                            cm.replaceSelection('[icon name="' + payload.name + '"' + styleAttr + ']');
                        });
                    },
                    className: 'fa fa-flag',
                    title: 'Insert Icon',
                });
            }

            if (shortcodesEnabled) {
                markdownToolbar.push({
                    name: 'shortcode-picker',
                    action: function () {
                        openShortcodePicker(container, function (text) {
                            editor.codemirror.replaceSelection(text);
                        });
                    },
                    // Not fa-code — the built-in 'code' toolbar button
                    // (inline code span) already uses that glyph;
                    // fa-terminal keeps the two visually distinct.
                    className: 'fa fa-terminal',
                    title: 'Insert Shortcode',
                });
            }

            if (emojiPickerEnabled) {
                markdownToolbar.push({
                    name: 'emoji-picker',
                    action: function () {
                        openEmojiPicker(container, function (emoji) {
                            editor.codemirror.replaceSelection(emoji);
                        });
                    },
                    className: 'fa fa-smile-o',
                    title: 'Insert Emoji',
                });
            }

            (editorConfig.buttons || []).forEach(function (custom) {
                markdownToolbar.push({
                    name: custom.name,
                    action: function () {
                        var cm = editor.codemirror;
                        var selected = cm.getSelection();
                        cm.replaceSelection(custom.before + selected + custom.after);
                        // Park the cursor between the markers when nothing was selected.
                        if (selected === '' && custom.after !== '') {
                            var cursor = cm.getCursor();
                            cm.setCursor({ line: cursor.line, ch: cursor.ch - custom.after.length });
                        }
                        cm.focus();
                    },
                    className: 'fa ' + custom.icon,
                    title: custom.title,
                });
            });

            markdownToolbar.push('table', 'horizontal-rule', '|', 'preview', 'side-by-side', 'fullscreen', '|', 'guide', {
                name: 'keyboard-shortcuts',
                action: function () { openShortcutsReference(); },
                className: 'fa fa-keyboard-o',
                title: 'Keyboard Shortcuts',
            });
            markdownToolbar = filterToolbar(markdownToolbar, editorConfig.hide || []);

            var editor = new EasyMDE({
                element: textarea,
                // Font Awesome is loaded explicitly above (loadStyle(
                // FONT_AWESOME_CSS)) instead of via this option, so its
                // origin is one this app's CSP actually allows.
                autoDownloadFontAwesome: false,
                spellChecker: true,
                status: false,
                uploadImage: true,
                imageUploadFunction: function (file, onSuccess, onError) {
                    uploadFile(container, file).then(function (json) {
                        onSuccess(json.url);
                    }).catch(function (error) {
                        onError(error.message || 'Upload failed.');
                    });
                },
                // uniqueId keys off data-autosave-id (post-{id}/page-{id}
                // from admin/views/posts/new.php / pages.php) rather than
                // textarea.id, which is a static "post-content"/
                // "page-content" shared by every post/page — using it as
                // the autosave key meant every post's (or every brand-new,
                // never-saved post's) EasyMDE instance shared the exact
                // same localStorage slot, so a fresh "Add New Post" could
                // silently restore whatever content was last autosaved
                // anywhere else (LP-068). Autosave is disabled outright
                // for a not-yet-saved post/page (no id to key it by yet)
                // rather than risk the same collision between two
                // different unsaved drafts.
                autosave: autosaveId !== '' ? {
                    enabled: true,
                    uniqueId: 'lp-autosave-' + autosaveId,
                    delay: editorConfig.autosaveDelay || 15000,
                } : { enabled: false },
                toolbar: markdownToolbar,
            });

            // EasyMDE hides the original <textarea> behind its CodeMirror
            // UI and does not keep its .value live-synced on every
            // keystroke — only writing it back here (rather than relying
            // on form-submit-time syncing alone) guarantees the real form
            // field is always current, including for the format-switch
            // conversion flow below, which reads the textarea directly.
            editor.codemirror.on('change', function () {
                textarea.value = editor.value();
                updateStats(statsEl, editor.value());
            });
            textarea.value = editor.value();
            updateStats(statsEl, editor.value());

            return {
                getValue: function () { return editor.value(); },
                destroy: function () { editor.toTextArea(); },
            };
        });
    }

    // ------------------------------------------------------------------
    // WYSIWYG editor (TinyMCE)
    // ------------------------------------------------------------------

    function initWysiwygEditor(container, textarea, statsEl) {
        return loadScript(TINYMCE_JS).then(function () {
            var tinymce = window.tinymce;
            var autosaveId = container.dataset.autosaveId || '';
            var iconPickerEnabled = !!container.dataset.iconPickerCsrf;
            var emojiPickerEnabled = container.dataset.emojiWysiwygEnabled === '1';
            var shortcodesEnabled = Object.keys(JSON.parse(container.dataset.shortcodes || '{}')).length > 0;
            // LP-115: the native 'image' plugin/toolbar button is
            // deliberately not loaded — lumoraMedia (the Media Manager
            // picker) is this editor's single "Insert Image" entry
            // point, not a second, redundant bare URL/upload dialog.
            // LP-130: the native 'link' plugin is left out for the same
            // reason — lumoraLink (openLinkPicker() below) fully
            // replaces its dialog with one that can also target an
            // existing Post/Page from a live, searchable list.
            // LP-168: 'quickbars' only powers the floating image toolbar
            // configured below (quickbars_image_toolbar) — its own
            // default insert/selection toolbars are turned off, since
            // their "quickimage" button would reopen exactly the native
            // image dialog LP-115 above deliberately left out.
            var basePlugins = 'lists table code codesample searchreplace fullscreen wordcount help quickbars';

            // Both are real linked stylesheets loaded into the iframe via
            // content_css — the admin CSP's style-src 'self' has no
            // 'unsafe-inline', so TinyMCE's own inline content_style
            // option is silently dropped there (see the content_css
            // comment below).
            var contentCssUrls = [];

            if (container.dataset.moreTagStylesheet) {
                contentCssUrls.push(container.dataset.moreTagStylesheet);
            }

            if (container.dataset.themeStylesheet) {
                contentCssUrls.push(container.dataset.themeStylesheet);
            }

            // Same has-{color}-color class convention as the align
            // formats below — one custom format per fixed palette color,
            // consumed by the lumoraFontColor menu button in setup().
            var fontColorFormats = {};
            FONT_COLORS.forEach(function (color) {
                fontColorFormats['fontcolor-' + color.name] = { inline: 'span', classes: 'has-' + color.name + '-color' };
            });

            return new Promise(function (resolve) {
                tinymce.init({
                    target: textarea,
                    license_key: 'gpl',
                    height: 420,
                    menubar: false,
                    // Only enabled (and only added to the plugin list) once
                    // there's a real post/page id to key the storage slot
                    // by — TinyMCE's default autosave_prefix already
                    // includes the page URL, which is enough to keep
                    // different *existing* posts/pages from colliding, but
                    // "Add New Post"/"Add New Page" is the same URL for
                    // every brand-new, never-saved draft, so it would
                    // otherwise still hit the same bug EasyMDE's autosave
                    // had (LP-068).
                    plugins: basePlugins + (autosaveId !== '' ? ' autosave' : ''),
                    toolbar: 'undo redo | blocks | bold italic underline strikethrough lumoraFontColor | '
                        + 'aligncenter alignleft alignright alignjustify | '
                        + 'lumoraMoreTag bullist numlist | blockquote hr | lumoraLink lumoraMedia lumoraEditImage lumoraFolderGallery lumoraAudio lumoraVideo '
                        + (iconPickerEnabled ? 'lumoraIcon ' : '') + (emojiPickerEnabled ? 'lumoraEmoji ' : '') + (shortcodesEnabled ? 'lumoraShortcode ' : '') + 'code codesample | '
                        + 'searchreplace fullscreen table help',
                    // Default 'floating' collapses whatever doesn't fit
                    // the editor's width behind a "..." overflow button —
                    // this toolbar's full button set never fits, so most
                    // buttons (including Insert/Edit Link) sat hidden
                    // there. 'wrap' lays overflowing groups onto
                    // additional rows instead, every button visible at
                    // a glance.
                    toolbar_mode: 'wrap',
                    // LP-168: clicking an image shows a small floating
                    // toolbar right next to it with only "Insert/Edit
                    // Link" (the same lumoraLink button/dialog the main
                    // toolbar already uses) — a direct, one-click path to
                    // linking an image without reaching for the main
                    // toolbar. The default insert/selection quickbars are
                    // disabled since neither applies here.
                    quickbars_insert_toolbar: false,
                    quickbars_selection_toolbar: false,
                    quickbars_image_toolbar: 'lumoraEditImage lumoraLink',
                    // LP-079: visually distinguishes the More tag marker
                    // (span.lp-more-tag) while editing — never on the public
                    // site (the marker itself is always stripped before
                    // the_content()/the_excerpt() render anything — see
                    // ContentRenderer::splitAtMoreTag()), so it's safe to
                    // make the marker look nothing like its final
                    // (nonexistent) public appearance. Shipped as a real
                    // linked stylesheet (content_css) rather than TinyMCE's
                    // own inline content_style option — the admin CSP's
                    // style-src 'self' (no 'unsafe-inline') silently drops
                    // an unnonced inline <style>, which is exactly what
                    // content_style injects into the iframe.
                    content_css: contentCssUrls,
                    // TinyMCE's align toolbar defaults to an inline
                    // style="text-align: ..." — HtmlSanitizer never
                    // allows a style attribute at all (an arbitrary-CSS
                    // injection surface this project deliberately
                    // avoids), so alignment is applied as a class
                    // instead, the same has-text-align-* convention
                    // Gutenberg uses. See content/themes/lumora-classic/
                    // style.css for the matching CSS.
                    formats: Object.assign({
                        alignleft: { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div', classes: 'has-text-align-left' },
                        aligncenter: { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div', classes: 'has-text-align-center' },
                        alignright: { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div', classes: 'has-text-align-right' },
                        alignjustify: { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div', classes: 'has-text-align-justify' },
                        // TinyMCE's default underline format applies
                        // <span style="text-decoration: underline">, the
                        // same style-attribute problem as alignment above
                        // — HtmlSanitizer strips it, silently discarding
                        // the underline. Force the plain <u> tag it
                        // already allows instead.
                        underline: { inline: 'u', exact: true },
                    }, fontColorFormats),
                    branding: false,
                    promotion: false,
                    // TinyMCE's default (relative_urls: true) silently
                    // rewrites every inserted/typed absolute URL — image
                    // src, link href — into a path relative to the admin
                    // editor's own page location (e.g. "../../content/
                    // uploads/..."). That's only ever valid from the
                    // editor page itself; once the same stored HTML
                    // renders on a public post/page at a different URL
                    // depth, the relative path resolves to the wrong
                    // place and 404s. Content this app stores must remain
                    // correct wherever it's later rendered, so URLs
                    // inserted here (via Insert from Media Manager or
                    // typed by hand) must stay exactly as given.
                    relative_urls: false,
                    images_upload_handler: function (blobInfo) {
                        return uploadFile(container, blobInfo.blob()).then(function (json) {
                            return json.url;
                        });
                    },
                    autosave_interval: '15s',
                    autosave_prefix: 'lp-tinymce-autosave-' + autosaveId + '-',
                    setup: function (editor) {
                        // Fixed-palette font color (LP-016 parity with
                        // Markdown's [text]{.color}) — a menu of swatches
                        // toggling the fontcolor-{name} formats registered
                        // above, rather than TinyMCE's default forecolor
                        // button, which applies an inline style="color:..."
                        // HtmlSanitizer would strip right back out.
                        editor.ui.registry.addMenuButton('lumoraFontColor', {
                            icon: 'text-color',
                            tooltip: 'Font Color',
                            fetch: function (callback) {
                                var items = FONT_COLORS.map(function (color) {
                                    return {
                                        type: 'togglemenuitem',
                                        text: color.label,
                                        onAction: function () {
                                            editor.formatter.toggle('fontcolor-' + color.name);
                                            editor.nodeChanged();
                                        },
                                        onSetup: function (api) {
                                            api.setActive(editor.formatter.match('fontcolor-' + color.name));

                                            return function () {};
                                        },
                                    };
                                });

                                items.push({
                                    type: 'menuitem',
                                    text: 'Remove Color',
                                    onAction: function () {
                                        FONT_COLORS.forEach(function (color) {
                                            editor.formatter.remove('fontcolor-' + color.name);
                                        });
                                    },
                                });

                                callback(items);
                            },
                        });

                        editor.ui.registry.addButton('lumoraLink', {
                            icon: 'link',
                            tooltip: 'Insert/Edit Link',
                            onAction: function () {
                                openLinkPicker(container, editor);
                            },
                        });

                        // Matches the keyboard shortcut the native link
                        // plugin (no longer loaded — see basePlugins
                        // above) would otherwise have registered.
                        editor.addShortcut('meta+k', 'Insert/Edit Link', function () {
                            openLinkPicker(container, editor);
                        });

                        editor.ui.registry.addButton('lumoraMedia', {
                            icon: 'image',
                            tooltip: 'Insert Image',
                            onAction: function () {
                                openMediaPicker(container, function (payload) {
                                    // LP-080: "Link To: None" means no link
                                    // at all — see the identical comment on
                                    // the Markdown insertion above for why
                                    // no-lightbox is needed even for an
                                    // otherwise-unlinked image.
                                    // A captioned image carries its alignment on
                                    // the wrapping <figure> instead, so the theme
                                    // floats the image and caption together.
                                    var classAttr = 'size-' + payload.size + (payload.caption ? '' : ' ' + payload.align) + (payload.linkUrl ? '' : ' no-lightbox');
                                    var image = '<img src="' + escapeHtmlAttr(payload.url) + '" alt="' + escapeHtmlAttr(payload.alt) + '"'
                                        + imageSizeHtml(payload)
                                        + ' class="' + classAttr + '">';
                                    // Inserting with the cursor inside an existing
                                    // captioned image would nest the new image in
                                    // its <figure>/<figcaption>, so move to a new
                                    // empty paragraph after it first. Focus comes
                                    // first: otherwise insertContent() restores the
                                    // selection saved when the dialog opened.
                                    editor.focus();
                                    var enclosingFigure = editor.dom.getParent(editor.selection.getNode(), 'figure');

                                    if (enclosingFigure) {
                                        var afterFigure = editor.dom.create('p', {}, '<br data-mce-bogus="1">');
                                        editor.dom.insertAfter(afterFigure, enclosingFigure);
                                        editor.selection.setCursorLocation(afterFigure, 0);
                                    }

                                    var withCaption = function (html) {
                                        if (!payload.caption) {
                                            return html;
                                        }

                                        return '<figure class="lp-caption ' + escapeHtmlAttr(payload.align) + '">' + html
                                            + '<figcaption>' + escapeHtmlAttr(payload.caption) + '</figcaption></figure>';
                                    };

                                    if (!payload.linkUrl) {
                                        editor.insertContent(withCaption(image));

                                        return;
                                    }

                                    // The link's own data-pswp-width/height/
                                    // caption are set directly here, from the
                                    // *linked* file's real dimensions —
                                    // needed because a chosen display size
                                    // can differ from Full, so the lightbox
                                    // (ContentRenderer::addLightboxAttributes())
                                    // can't reliably infer the linked file's
                                    // real size from the inline <img> alone,
                                    // which only ever describes its own,
                                    // possibly smaller, display size.
                                    var link = '<a href="' + escapeHtmlAttr(payload.linkUrl) + '"'
                                        + (payload.linkWidth ? ' data-pswp-width="' + payload.linkWidth + '"' : '')
                                        + (payload.linkHeight ? ' data-pswp-height="' + payload.linkHeight + '"' : '')
                                        + ' data-pswp-caption="' + escapeHtmlAttr(payload.caption || payload.alt) + '"'
                                        + '>' + image + '</a>';

                                    editor.insertContent(withCaption(link));
                                });
                            },
                        });

                        editor.ui.registry.addButton('lumoraEditImage', {
                            icon: 'edit-image',
                            tooltip: 'Edit Image',
                            onAction: function () {
                                openTinyMceImageEditor(editor);
                            },
                            onSetup: function (buttonApi) {
                                var update = function () {
                                    buttonApi.setEnabled(isEditableImage(editor.selection.getNode()));
                                };

                                editor.on('NodeChange', update);
                                update();

                                return function () { editor.off('NodeChange', update); };
                            },
                        });

                        // Double-clicking an image is the usual shortcut for editing it.
                        editor.on('dblclick', function (event) {
                            if (isEditableImage(event.target)) {
                                editor.selection.select(event.target);
                                openTinyMceImageEditor(editor);
                            }
                        });

                        editor.ui.registry.addButton('lumoraFolderGallery', {
                            icon: 'gallery',
                            tooltip: 'Insert Folder',
                            onAction: function () {
                                openFolderGalleryPicker(container, function (payload) {
                                    var link = payload.link === 'file' ? 'full' : 'none';
                                    editor.insertContent('[lumora_folder_gallery folder_id="' + payload.folderId + '" size="' + payload.size + '" link="' + link + '"]');
                                });
                            },
                        });

                        editor.ui.registry.addButton('lumoraAudio', {
                            icon: 'audio',
                            tooltip: 'Insert Audio',
                            onAction: function () {
                                openMediaPlayerPicker(container, 'audio', function (payload) {
                                    editor.insertContent('[lumora_audio id="' + payload.id + '"]');
                                });
                            },
                        });

                        editor.ui.registry.addButton('lumoraVideo', {
                            icon: 'video',
                            tooltip: 'Insert Video',
                            onAction: function () {
                                openMediaPlayerPicker(container, 'video', function (payload) {
                                    editor.insertContent('[lumora_video id="' + payload.id + '"]');
                                });
                            },
                        });

                        if (iconPickerEnabled) {
                            editor.ui.registry.addButton('lumoraIcon', {
                                icon: 'insert-character',
                                tooltip: 'Insert Icon',
                                onAction: function () {
                                    openIconPicker(container, function (payload) {
                                        var styleAttr = payload.style !== 'solid' ? ' style="' + escapeHtmlAttr(payload.style) + '"' : '';
                                        editor.insertContent('[icon name="' + escapeHtmlAttr(payload.name) + '"' + styleAttr + ']');
                                    });
                                },
                            });
                        }

                        if (shortcodesEnabled) {
                            editor.ui.registry.addButton('lumoraShortcode', {
                                icon: 'addtag',
                                tooltip: 'Insert Shortcode',
                                onAction: function () {
                                    openShortcodePicker(container, function (text) {
                                        editor.insertContent(text);
                                    });
                                },
                            });
                        }

                        if (emojiPickerEnabled) {
                            editor.ui.registry.addButton('lumoraEmoji', {
                                icon: 'emoji',
                                tooltip: 'Insert Emoji',
                                onAction: function () {
                                    openEmojiPicker(container, function (emoji) {
                                        editor.insertContent(emoji);
                                    });
                                },
                            });
                        }

                        // LP-079 — inserts a whole paragraph containing
                        // only the More tag marker, mirroring
                        // insertMoreTag()'s Markdown-side blank-line-
                        // separated block. Visible "Read More" label text
                        // is included so the marker isn't just an empty,
                        // hard-to-select inline element while editing —
                        // see ContentRenderer::MORE_TAG_HTML_MARKER_PATTERN's
                        // docblock for why that text is safe to include
                        // (matched, and discarded, as part of the whole
                        // marker element).
                        editor.ui.registry.addButton('lumoraMoreTag', {
                            icon: 'cut',
                            tooltip: 'Insert Read More Tag',
                            onAction: function () {
                                editor.insertContent('<p><span class="lp-more-tag">Read More</span></p>');
                            },
                        });

                        editor.on('change keyup', function () {
                            editor.save();
                            updateStats(statsEl, editor.getContent({ format: 'text' }));
                        });

                        editor.on('init', function () {
                            updateStats(statsEl, editor.getContent({ format: 'text' }));
                            resolve({
                                getValue: function () { return editor.getContent(); },
                                destroy: function () { editor.remove(); },
                            });
                        });
                    },
                });
            });
        });
    }

    // ------------------------------------------------------------------
    // Plain text — no rich editor, just live word/char/reading-time stats.
    // ------------------------------------------------------------------

    function initPlainEditor(container, textarea, statsEl) {
        textarea.addEventListener('input', function () {
            updateStats(statsEl, textarea.value);
        });
        updateStats(statsEl, textarea.value);

        return Promise.resolve({
            getValue: function () { return textarea.value; },
            destroy: function () {},
        });
    }

    function initEditorForFormat(format, container, textarea, statsEl) {
        if (format === 'markdown') {
            return initMarkdownEditor(container, textarea, statsEl);
        }

        if (format === 'html') {
            return initWysiwygEditor(container, textarea, statsEl);
        }

        return initPlainEditor(container, textarea, statsEl);
    }

    // ------------------------------------------------------------------
    // Wiring
    // ------------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', function () {
        var containers = document.querySelectorAll('[data-lp-content-editor]');

        containers.forEach(function (container) {
            var textarea = container.querySelector('textarea');

            if (!textarea) {
                return;
            }

            var statsEl = document.createElement('p');
            statsEl.className = 'lp-content-editor__stats';
            container.appendChild(statsEl);

            var current = null;

            function boot(format) {
                initEditorForFormat(format, container, textarea, statsEl).then(function (instance) {
                    current = instance;
                });
            }

            boot(container.dataset.format || 'markdown');

            var select = document.querySelector('[data-lp-content-format-select]');

            if (!select) {
                return;
            }

            select.addEventListener('change', function () {
                var fromFormat = container.dataset.format;
                var toFormat = select.value;

                if (fromFormat === toFormat) {
                    return;
                }

                if (!window.confirm('Convert the current content to ' + toFormat + '? This is a best-effort conversion — review the result before saving.')) {
                    select.value = fromFormat;

                    return;
                }

                var currentValue = current ? current.getValue() : textarea.value;
                var formData = new FormData();
                formData.append('form', 'convert_content');
                formData.append('csrf_token', container.dataset.convertCsrf);
                formData.append('content', currentValue);
                formData.append('from', fromFormat);
                formData.append('to', toFormat);

                fetch(container.dataset.uploadUrl, { method: 'POST', body: formData })
                    .then(function (response) {
                        return response.json().then(function (json) {
                            return { ok: response.ok, json: json };
                        });
                    })
                    .then(function (result) {
                        var json = result.json || {};

                        if (json.csrf_token) {
                            document.querySelectorAll('[data-convert-csrf]').forEach(function (editorContainer) {
                                editorContainer.dataset.convertCsrf = json.csrf_token;
                            });
                        }

                        // Switching editors without converted content would
                        // leave e.g. raw HTML in the Markdown editor, saved as
                        // the wrong format, so stay put and say why instead.
                        if (!result.ok || json.content === undefined) {
                            select.value = fromFormat;
                            window.alert(json.error || 'The content could not be converted. Reload the page and try again.');

                            return;
                        }

                        if (current) {
                            current.destroy();
                            current = null;
                        }

                        textarea.value = json.content;
                        container.dataset.format = toFormat;
                        boot(toFormat);
                    })
                    .catch(function () {
                        select.value = fromFormat;
                        window.alert('The content could not be converted. Reload the page and try again.');
                    });
            });
        });

        wireStandaloneEmojiTriggers();
    });
}());
