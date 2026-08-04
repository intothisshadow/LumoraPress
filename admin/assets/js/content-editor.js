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
    // ------------------------------------------------------------------

    function openMediaPicker(library, onSelect) {
        var dialog = document.createElement('dialog');
        dialog.className = 'lp-editor-media-dialog';

        var grid = document.createElement('div');
        grid.className = 'lp-editor-media-dialog__grid';

        if (library.length === 0) {
            var empty = document.createElement('p');
            empty.textContent = 'No images in the Media Manager yet.';
            grid.appendChild(empty);
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
                onSelect(item);
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
                    'code', 'quote', 'unordered-list', 'ordered-list', '|',
                    'link', 'image',
                    {
                        name: 'media-library',
                        action: function () {
                            openMediaPicker(library, function (item) {
                                var cm = editor.codemirror;
                                cm.replaceSelection('![' + item.name + '](' + item.url + ')');
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
                        + 'bullist numlist | blockquote hr | link image lumoraMedia table codesample | '
                        + 'searchreplace fullscreen code help',
                    branding: false,
                    promotion: false,
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
                                openMediaPicker(library, function (item) {
                                    editor.insertContent('<img src="' + item.url + '" alt="' + item.name + '">');
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
