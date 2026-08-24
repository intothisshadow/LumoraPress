/**
 * Text/HTML widget WYSIWYG (LP-112) — boots a lightweight TinyMCE instance
 * against [data-lp-widget-wysiwyg] textareas on the Appearance > Widgets
 * screen.
 *
 * Deliberately a separate, smaller instance rather than reusing
 * content-editor.js's initWysiwygEditor(): that function assumes a single
 * per-page post/page editor (upload endpoint, media library JSON, autosave
 * key), none of which exist for a widget instance — a sidebar can hold
 * several Text/HTML widgets on the same admin page at once, each needing
 * its own editor with no shared upload/autosave state. Image uploads and
 * the Media Manager picker are intentionally left out; a widget's rendered
 * output is meant to stay small (a blurb, a notice, a bit of formatted
 * text), and the Custom HTML widget already exists for anything more
 * elaborate an administrator wants to hand-author.
 *
 * Markup contract (see admin/views/appearance/widgets.php):
 *   <div data-lp-widget-wysiwyg-container data-theme-stylesheet="...">
 *     <textarea data-lp-widget-wysiwyg>...</textarea>
 *   </div>
 */
(function () {
    'use strict';

    // Same pinned version/CDN as content-editor.js's TinyMCE load — kept in
    // sync by hand since each admin script here is a standalone file with
    // no shared build step (see that file's own docblock on why EasyMDE/
    // TinyMCE are jsDelivr-hosted rather than vendored).
    var TINYMCE_VERSION = '7';
    var TINYMCE_JS = 'https://cdn.jsdelivr.net/npm/tinymce@' + TINYMCE_VERSION + '/tinymce.min.js';

    var scriptPromise = null;

    function loadTinyMce() {
        if (scriptPromise) {
            return scriptPromise;
        }

        scriptPromise = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = TINYMCE_JS;
            script.onload = function () { resolve(); };
            script.onerror = function () { reject(new Error('Failed to load ' + TINYMCE_JS)); };
            document.head.appendChild(script);
        });

        return scriptPromise;
    }

    function initWidgetEditor(container, textarea) {
        return loadTinyMce().then(function () {
            var tinymce = window.tinymce;

            return new Promise(function (resolve) {
                tinymce.init({
                    target: textarea,
                    license_key: 'gpl',
                    height: 260,
                    menubar: false,
                    statusbar: false,
                    plugins: 'lists link code',
                    toolbar: 'bold italic underline strikethrough | '
                        + 'bullist numlist | blockquote | '
                        + 'alignleft aligncenter alignright alignjustify | '
                        + 'link | code',
                    // Same has-text-align-* class convention as
                    // content-editor.js's WYSIWYG editor — HtmlSanitizer
                    // never allows a style attribute, so alignment must be
                    // applied as a class rather than inline text-align.
                    formats: {
                        alignleft: { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div', classes: 'has-text-align-left' },
                        aligncenter: { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div', classes: 'has-text-align-center' },
                        alignright: { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div', classes: 'has-text-align-right' },
                        alignjustify: { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div', classes: 'has-text-align-justify' },
                    },
                    branding: false,
                    promotion: false,
                    // See content-editor.js's identical option for why
                    // relative_urls must stay off: a widget renders on
                    // every page it's assigned to, not just this editor's
                    // own admin URL, so a relative link/image path here
                    // would resolve to the wrong place once rendered.
                    relative_urls: false,
                    content_css: (container.dataset.themeStylesheet || undefined),
                    setup: function (editor) {
                        editor.on('change keyup', function () {
                            editor.save();
                        });

                        editor.on('init', function () {
                            resolve({ destroy: function () { editor.remove(); } });
                        });
                    },
                });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var containers = document.querySelectorAll('[data-lp-widget-wysiwyg-container]');

        containers.forEach(function (container) {
            var textarea = container.querySelector('[data-lp-widget-wysiwyg]');

            if (!textarea) {
                return;
            }

            // Each widget's settings form lives inside a collapsed
            // <details> (admin/views/appearance/widgets.php) until an
            // admin clicks it open — TinyMCE measures its toolbar/iframe
            // against the space available at init time, so initializing
            // while still display:none (the closed <details> default)
            // would leave it zero-height. Deferred to the details' first
            // "toggle" open instead of eagerly on page load.
            var details = container.closest('details');

            if (!details || details.open) {
                initWidgetEditor(container, textarea);

                return;
            }

            function onToggle() {
                if (!details.open) {
                    return;
                }

                details.removeEventListener('toggle', onToggle);
                initWidgetEditor(container, textarea);
            }

            details.addEventListener('toggle', onToggle);
        });
    });
}());
