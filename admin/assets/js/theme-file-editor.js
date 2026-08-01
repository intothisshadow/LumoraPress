/**
 * Theme File Editor (LP-050) progressive enhancement: swaps the plain
 * `<textarea>` in admin/views/appearance/editor.php's save form for a real
 * CodeMirror 5 editor (syntax highlighting, line numbers, code folding,
 * bracket matching/auto-closing, find & replace, go-to-line) and adds a
 * simple client-side filter for the file tree's search box.
 *
 * CodeMirror 5 loads from jsDelivr at a pinned version — same convention
 * admin/assets/js/content-editor.js documents for EasyMDE/TinyMCE (no
 * bundler, plain <script>/<link> tags, cdn.jsdelivr.net already allowed by
 * the CSP built in include/bootstrap.php). CM5 (not CM6) specifically,
 * since it's a plain global/UMD build like the CodeMirror already vendored
 * transitively inside EasyMDE, rather than CM6's ES-module-native design
 * this project has never needed to support before.
 *
 * The <textarea> is always the real form field — CodeMirror's own
 * `change` handler copies into it on every keystroke, and the form's own
 * `submit` handler does the same right before submission, so content still
 * saves even if the CDN fails to load (the plain textarea degrades
 * gracefully, same posture content-editor.js documents).
 */
(function () {
    'use strict';

    var CM_VERSION = '5.65.16';
    var CM_BASE = 'https://cdn.jsdelivr.net/npm/codemirror@' + CM_VERSION;

    var MODES = {
        php: { mime: 'application/x-httpd-php', scripts: ['mode/xml/xml.js', 'mode/javascript/javascript.js', 'mode/css/css.js', 'mode/clike/clike.js', 'mode/htmlmixed/htmlmixed.js', 'mode/php/php.js'] },
        css: { mime: 'css', scripts: ['mode/css/css.js'] },
        js: { mime: 'javascript', scripts: ['mode/javascript/javascript.js'] },
        json: { mime: 'application/json', scripts: ['mode/javascript/javascript.js'] },
        html: { mime: 'htmlmixed', scripts: ['mode/xml/xml.js', 'mode/javascript/javascript.js', 'mode/css/css.js', 'mode/htmlmixed/htmlmixed.js'] },
        htm: { mime: 'htmlmixed', scripts: ['mode/xml/xml.js', 'mode/javascript/javascript.js', 'mode/css/css.js', 'mode/htmlmixed/htmlmixed.js'] },
        xml: { mime: 'xml', scripts: ['mode/xml/xml.js'] },
        svg: { mime: 'xml', scripts: ['mode/xml/xml.js'] },
        md: { mime: 'markdown', scripts: ['mode/markdown/markdown.js'] },
        txt: { mime: 'text/plain', scripts: [] },
    };

    var ADDON_SCRIPTS = [
        'addon/edit/matchbrackets.js',
        'addon/edit/closebrackets.js',
        'addon/fold/foldcode.js',
        'addon/fold/foldgutter.js',
        'addon/fold/brace-fold.js',
        'addon/fold/xml-fold.js',
        'addon/fold/indent-fold.js',
        'addon/fold/comment-fold.js',
        'addon/search/searchcursor.js',
        'addon/search/search.js',
        'addon/dialog/dialog.js',
        'addon/search/jump-to-line.js',
    ];

    var ADDON_STYLES = [
        'addon/fold/foldgutter.css',
        'addon/dialog/dialog.css',
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

    function loadSequential(urls) {
        // Mode/addon scripts register themselves onto the global
        // CodeMirror object and some depend on an earlier one already
        // having run (e.g. htmlmixed needs xml/javascript/css loaded
        // first, php needs htmlmixed) — loaded one at a time, in order,
        // rather than in parallel.
        return urls.reduce(function (chain, url) {
            return chain.then(function () { return loadScript(url); });
        }, Promise.resolve());
    }

    function initFileSearch() {
        var searchInput = document.querySelector('[data-lp-file-search]');

        if (!searchInput) {
            return;
        }

        searchInput.addEventListener('input', function () {
            var query = searchInput.value.trim().toLowerCase();

            document.querySelectorAll('[data-file-search]').forEach(function (node) {
                var haystack = node.getAttribute('data-file-search') || '';
                node.style.display = (query === '' || haystack.indexOf(query) !== -1) ? '' : 'none';
            });
        });
    }

    function initEditor(container) {
        var textarea = container.querySelector('textarea');

        if (!textarea) {
            return;
        }

        var extension = container.dataset.extension || 'txt';
        var modeInfo = MODES[extension] || MODES.txt;

        loadStyle(CM_BASE + '/lib/codemirror.css');
        ADDON_STYLES.forEach(function (path) { loadStyle(CM_BASE + '/' + path); });

        loadScript(CM_BASE + '/lib/codemirror.js')
            .then(function () {
                return loadSequential(modeInfo.scripts.map(function (path) { return CM_BASE + '/' + path; }));
            })
            .then(function () {
                return loadSequential(ADDON_SCRIPTS.map(function (path) { return CM_BASE + '/' + path; }));
            })
            .then(function () {
                var CodeMirror = window.CodeMirror;

                var editor = CodeMirror.fromTextArea(textarea, {
                    mode: modeInfo.mime,
                    readOnly: textarea.hasAttribute('readonly'),
                    lineNumbers: true,
                    matchBrackets: true,
                    autoCloseBrackets: true,
                    foldGutter: true,
                    gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter'],
                    indentUnit: 4,
                    tabSize: 4,
                    theme: 'default',
                    extraKeys: {
                        'Ctrl-F': 'findPersistent',
                        'Cmd-F': 'findPersistent',
                        'Ctrl-H': 'replace',
                        'Shift-Ctrl-H': 'replaceAll',
                        'Alt-G': 'jumpToLine',
                    },
                });

                editor.setSize(null, 480);

                var form = container.closest('form');
                var dirty = false;

                editor.on('change', function () {
                    textarea.value = editor.getValue();
                    dirty = true;
                });

                if (form) {
                    form.addEventListener('submit', function () {
                        textarea.value = editor.getValue();
                        dirty = false;
                    });
                }

                window.addEventListener('beforeunload', function (event) {
                    if (!dirty) {
                        return;
                    }

                    event.preventDefault();
                    event.returnValue = '';
                });

                var toolbar = document.createElement('div');
                toolbar.className = 'lp-theme-editor__cm-toolbar';

                var wrapLabel = document.createElement('label');
                var wrapCheckbox = document.createElement('input');
                wrapCheckbox.type = 'checkbox';
                wrapCheckbox.addEventListener('change', function () {
                    editor.setOption('lineWrapping', wrapCheckbox.checked);
                });
                wrapLabel.appendChild(wrapCheckbox);
                wrapLabel.appendChild(document.createTextNode(' Word wrap'));

                var darkLabel = document.createElement('label');
                var darkCheckbox = document.createElement('input');
                darkCheckbox.type = 'checkbox';
                darkCheckbox.addEventListener('change', function () {
                    if (darkCheckbox.checked) {
                        loadStyle(CM_BASE + '/theme/material-darker.css');
                        editor.setOption('theme', 'material-darker');
                    } else {
                        editor.setOption('theme', 'default');
                    }
                });
                darkLabel.appendChild(darkCheckbox);
                darkLabel.appendChild(document.createTextNode(' Dark theme'));

                toolbar.appendChild(wrapLabel);
                toolbar.appendChild(darkLabel);
                container.parentNode.insertBefore(toolbar, container);
            })
            .catch(function () {
                // CDN failure — the plain <textarea> is already the real
                // form field and stays fully usable without CodeMirror.
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initFileSearch();
        document.querySelectorAll('[data-lp-theme-file-editor]').forEach(initEditor);
    });
}());
