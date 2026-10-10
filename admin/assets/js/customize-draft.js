/**
 * Customize screen: "Discard changes", an unsaved-changes warning, and "Preview".
 *
 * Markup contract (see admin/views/appearance/customize.php): each option form has
 * [data-lp-customize-form] with a [data-lp-discard] button (hidden until the form changes),
 * a [data-lp-preview-options] button carrying the section, preview URL and a single-use CSRF
 * token, and a [data-lp-draft-status] line for messages.
 *
 * An external file because the admin Content-Security-Policy allows no inline script.
 */
(function () {
    'use strict';

    var dirty = new Set();
    var leaving = false;

    function setup(form) {
        var discard = form.querySelector('[data-lp-discard]');
        var preview = form.querySelector('[data-lp-preview-options]');
        var status = form.querySelector('[data-lp-draft-status]');

        function markDirty() {
            dirty.add(form);

            if (discard) {
                discard.hidden = false;
            }
        }

        // Only real user edits count: isTrusted skips the change events other scripts
        // (the editors' own set-up) dispatch while the page loads.
        form.addEventListener('input', function (event) {
            if (event.isTrusted) {
                markDirty();
            }
        });
        form.addEventListener('change', function (event) {
            if (event.isTrusted) {
                markDirty();
            }
        });
        form.addEventListener('submit', function () {
            leaving = true;
        });

        if (discard) {
            discard.addEventListener('click', function () {
                leaving = true;
                // A plain GET of a URL that differs from the current one, so the browser doesn't
                // restore the edited form state it keeps for a same-URL reload.
                var target = (form.getAttribute('action') || window.location.pathname).replace(/[?&]discarded=1/, '');

                window.location.assign(target + (target.indexOf('?') === -1 ? '?' : '&') + 'discarded=1');
            });
        }

        if (preview) {
            preview.addEventListener('click', function () {
                openPreview(form, preview, status);
            });
        }
    }

    function say(status, message) {
        if (status) {
            status.textContent = message;
        }
    }

    function openPreview(form, button, status) {
        // Opened now, while the click still counts as a user action; pop-up blockers refuse a window opened after the request.
        var preview = window.open('', '_blank');

        if (!preview) {
            say(status, 'Your browser blocked the preview window. Allow pop-ups for this site and try again.');

            return;
        }

        // The editors keep their text in their own widgets until saved.
        if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
            window.tinymce.triggerSave();
        }

        form.querySelectorAll('.CodeMirror').forEach(function (element) {
            if (element.CodeMirror && typeof element.CodeMirror.save === 'function') {
                element.CodeMirror.save();
            }
        });

        var data = new FormData(form);
        var files = [];

        data.forEach(function (value, key) {
            if (typeof File !== 'undefined' && value instanceof File) {
                files.push(key);
            }
        });
        files.forEach(function (key) {
            data.delete(key);
        });

        data.set('form', 'theme_options_preview');
        data.set('section', button.dataset.lpPreviewSection || '');
        data.set('csrf_token', button.dataset.lpPreviewCsrf || '');

        button.disabled = true;
        say(status, 'Preparing the preview…');

        fetch(button.dataset.lpPreviewUrl, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'fetch' }
        }).then(function (response) {
            return response.json().then(function (body) {
                return { ok: response.ok, body: body };
            });
        }).then(function (result) {
            // Each token works once; the response always carries the next.
            if (result.body.csrfToken) {
                button.dataset.lpPreviewCsrf = result.body.csrfToken;
            }

            if (result.ok && result.body.url) {
                preview.location.href = result.body.url;
                say(status, 'Preview opened in a new tab. Nothing is saved until you press Save.');
            } else {
                preview.close();
                say(status, result.body.error || 'The preview could not be created.');
            }
        }).catch(function () {
            preview.close();
            say(status, 'The preview could not be created. Reload the page and try again.');
        }).then(function () {
            button.disabled = false;
        });
    }

    function flagEditorChange(editor) {
        var form = editor.getElement().closest('[data-lp-customize-form]');

        if (!form) {
            return;
        }

        dirty.add(form);

        var discard = form.querySelector('[data-lp-discard]');

        if (discard) {
            discard.hidden = false;
        }
    }

    // The Visual editor reports edits through its own events, not the form's, and it loads after
    // this script, so look for it for a few seconds.
    function hookVisualEditor(attempt) {
        if (window.tinymce && typeof window.tinymce.on === 'function') {
            window.tinymce.on('AddEditor', function (added) {
                added.editor.on('change input undo redo', function () {
                    flagEditorChange(added.editor);
                });
            });

            (window.tinymce.editors || []).forEach(function (editor) {
                editor.on('change input undo redo', function () {
                    flagEditorChange(editor);
                });
            });

            return;
        }

        if (attempt < 20) {
            window.setTimeout(function () {
                hookVisualEditor(attempt + 1);
            }, 500);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-lp-customize-form]').forEach(setup);

        if (document.querySelector('[data-lp-customize-form]')) {
            window.addEventListener('beforeunload', function (event) {
                if (dirty.size > 0 && !leaving) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });
        }

        hookVisualEditor(0);
    });
}());
