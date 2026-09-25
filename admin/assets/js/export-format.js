/**
 * Maintenance > Export: when the chosen export format changes, disables
 * every content-type checkbox (and the "Include uploaded files" option)
 * that format can't carry. Each format's capabilities come from its own
 * data attributes, so a format added by a plugin needs no changes here.
 * The server applies the same restriction, so this is a convenience only.
 *
 * Markup contract:
 *   <input type="radio" data-lp-export-format
 *          data-lp-export-supported="posts pages ..."
 *          data-lp-export-bundles-uploads="1|0">
 *   <input type="checkbox" data-lp-export-type="posts">
 *     (optionally followed within its <label> by [data-lp-export-unsupported-note])
 *   <input type="checkbox" data-lp-export-uploads>
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var formats = document.querySelectorAll('[data-lp-export-format]');

        if (formats.length === 0) {
            return;
        }

        function apply() {
            var selected = document.querySelector('[data-lp-export-format]:checked');

            if (!selected) {
                return;
            }

            var supported = (selected.getAttribute('data-lp-export-supported') || '').split(/\s+/);

            document.querySelectorAll('[data-lp-export-type]').forEach(function (checkbox) {
                var isSupported = supported.indexOf(checkbox.getAttribute('data-lp-export-type')) !== -1;
                var note = checkbox.closest('label') ? checkbox.closest('label').querySelector('[data-lp-export-unsupported-note]') : null;

                checkbox.disabled = !isSupported;

                if (note) {
                    note.hidden = isSupported;
                }
            });

            document.querySelectorAll('[data-lp-export-uploads]').forEach(function (checkbox) {
                checkbox.disabled = selected.getAttribute('data-lp-export-bundles-uploads') !== '1';
            });
        }

        formats.forEach(function (radio) {
            radio.addEventListener('change', apply);
        });

        apply();
    });
}());
