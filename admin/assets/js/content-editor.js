/**
 * Content Editor orchestration (LP-015 Markdown / LP-016 WYSIWYG).
 *
 * Markup contract (see admin/views/posts.php / pages.php):
 *   <select data-lp-content-format-select>          — Markdown/HTML/Plain
 *   <div data-lp-content-editor
 *        data-format="markdown|html|plain"
 *        data-upload-url="..."
 *        data-upload-csrf="..."
 *        data-convert-csrf="..."
 *        data-media-library='[{"url":"...","name":"..."}]'>
 *     <textarea>...</textarea>
 *   </div>
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
    // Media picker — shared by both editors. Built from the same
    // already-fetched image list the page rendered into
    // data-media-library, rather than a separate AJAX endpoint.
    //
    // LP-075: picking an image is now a two-step flow — the grid, then
    // an "Attachment Display Settings" step (Size / Link To) before the
    // callback fires, mirroring classic WordPress's Insert Media dialog.
    // Each library item's `sizes` map ({full, small?, medium?, large?},
    // each {url, width, height} — see posts/new.php's/pages.php's
    // $editorMediaLibrary) is built server-side so this step needs no
    // extra request.
    // ------------------------------------------------------------------

    var SIZE_LABELS = { small: 'Thumbnail', medium: 'Medium', large: 'Large', full: 'Full Size' };
    var SIZE_ORDER = ['small', 'medium', 'large', 'full'];

    function escapeHtmlAttr(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function openMediaPicker(library, onSelect) {
        var dialog = document.createElement('dialog');
        dialog.className = 'lp-editor-media-dialog';

        var grid = document.createElement('div');
        grid.className = 'lp-editor-media-dialog__grid';

        var settings = document.createElement('div');
        settings.className = 'lp-editor-media-dialog__settings';
        settings.hidden = true;

        if (library.length === 0) {
            var empty = document.createElement('p');
            empty.textContent = 'No images in the Media Manager yet.';
            grid.appendChild(empty);
        }

        function showSettingsStep(item) {
            settings.innerHTML = '';
            grid.hidden = true;
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
            backButton.addEventListener('click', function () {
                settings.hidden = true;
                grid.hidden = false;
            });

            actions.appendChild(insertButton);
            actions.appendChild(backButton);

            settings.appendChild(sizeField);
            settings.appendChild(linkField);
            settings.appendChild(alignField);
            settings.appendChild(actions);
        }

        library.forEach(function (item) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'lp-editor-media-dialog__item';

            var img = document.createElement('img');
            img.src = item.url;
            img.alt = item.name;
            button.appendChild(img);

            button.addEventListener('click', function () {
                showSettingsStep(item);
            });

            grid.appendChild(button);
        });

        var closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'lp-button';
        closeButton.textContent = 'Cancel';
        closeButton.addEventListener('click', function () { dialog.close(); });

        dialog.appendChild(grid);
        dialog.appendChild(settings);
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
                if (json.error) {
                    throw new Error(json.error);
                }

                return json.url;
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

    function initMarkdownEditor(container, textarea, statsEl) {
        loadStyle(EASYMDE_CSS);
        loadStyle(FONT_AWESOME_CSS);

        return loadScript(EASYMDE_JS).then(function () {
            var EasyMDE = window.EasyMDE;
            var library = JSON.parse(container.dataset.mediaLibrary || '[]');
            var autosaveId = container.dataset.autosaveId || '';

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
                    uploadFile(container, file).then(onSuccess).catch(function (error) {
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
                    delay: 15000,
                } : { enabled: false },
                toolbar: [
                    'bold', 'italic', 'strikethrough', '|',
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
                    'code', 'quote', 'unordered-list', 'ordered-list', '|',
                    'link', 'image',
                    {
                        name: 'media-library',
                        action: function () {
                            openMediaPicker(library, function (payload) {
                                var cm = editor.codemirror;
                                // Markdown has no attribute syntax, so the
                                // chosen size is expressed purely by which
                                // file's URL gets inserted (LP-075) — no
                                // width/height survives into the rendered
                                // <img> for Markdown-authored content, a
                                // hard limitation of the format. Alignment
                                // (LP-016) is the one exception: a trailing
                                // {.alignleft/aligncenter/alignright} marker
                                // (MarkdownParser::parseImages()) does
                                // survive, the same minimal convention
                                // wrapSelectionWithAlignment() uses for
                                // heading/paragraph alignment above.
                                var alignMarker = payload.align && payload.align !== 'alignnone' ? '{.' + payload.align + '}' : '';
                                var image = '![' + payload.alt + '](' + payload.url + ')' + alignMarker;
                                cm.replaceSelection(payload.linkUrl ? '[' + image + '](' + payload.linkUrl + ')' : image);
                            });
                        },
                        className: 'fa fa-photo',
                        title: 'Insert from Media Manager',
                    },
                    'table', 'horizontal-rule', '|',
                    'preview', 'side-by-side', 'fullscreen', '|',
                    'guide',
                ],
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
            var library = JSON.parse(container.dataset.mediaLibrary || '[]');
            var autosaveId = container.dataset.autosaveId || '';
            var basePlugins = 'lists link image table code codesample searchreplace fullscreen wordcount help';

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
                    toolbar: 'undo redo | blocks | bold italic underline strikethrough | '
                        + 'aligncenter alignleft alignright alignjustify | '
                        + 'bullist numlist | blockquote hr | link image lumoraMedia table codesample | '
                        + 'searchreplace fullscreen code help',
                    // TinyMCE's align toolbar defaults to an inline
                    // style="text-align: ..." — HtmlSanitizer never
                    // allows a style attribute at all (an arbitrary-CSS
                    // injection surface this project deliberately
                    // avoids), so alignment is applied as a class
                    // instead, the same has-text-align-* convention
                    // Gutenberg uses. See content/themes/default/
                    // style.css for the matching CSS.
                    formats: {
                        alignleft: { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div', classes: 'has-text-align-left' },
                        aligncenter: { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div', classes: 'has-text-align-center' },
                        alignright: { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div', classes: 'has-text-align-right' },
                        alignjustify: { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div', classes: 'has-text-align-justify' },
                    },
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
                    content_css: (container.dataset.themeStylesheet || undefined),
                    images_upload_handler: function (blobInfo) {
                        return uploadFile(container, blobInfo.blob());
                    },
                    autosave_interval: '15s',
                    autosave_prefix: 'lp-tinymce-autosave-' + autosaveId + '-',
                    setup: function (editor) {
                        editor.ui.registry.addButton('lumoraMedia', {
                            icon: 'image',
                            tooltip: 'Insert from Media Manager',
                            onAction: function () {
                                openMediaPicker(library, function (payload) {
                                    var image = '<img src="' + escapeHtmlAttr(payload.url) + '" alt="' + escapeHtmlAttr(payload.alt) + '"'
                                        + (payload.width ? ' width="' + payload.width + '"' : '')
                                        + (payload.height ? ' height="' + payload.height + '"' : '')
                                        + ' class="size-' + payload.size + ' ' + payload.align + '">';

                                    if (!payload.linkUrl) {
                                        editor.insertContent(image);

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
                                        + ' data-pswp-caption="' + escapeHtmlAttr(payload.alt) + '"'
                                        + '>' + image + '</a>';

                                    editor.insertContent(link);
                                });
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
                    .then(function (response) { return response.json(); })
                    .then(function (json) {
                        if (current) {
                            current.destroy();
                            current = null;
                        }

                        textarea.value = json.content !== undefined ? json.content : currentValue;
                        container.dataset.format = toFormat;
                        boot(toFormat);
                    });
            });
        });
    });
}());
