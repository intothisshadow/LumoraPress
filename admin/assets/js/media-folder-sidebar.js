/**
 * Media Manager sidebar behavior on top of native <details>: dropdowns (a
 * folder's actions menu, the "More" views chip) close when another opens,
 * on Escape, and on a click outside; and the Folders panel starts
 * collapsed on narrow screens so the file list isn't pushed below a long
 * tree. Without JavaScript every <details> still opens and closes, and
 * the Folders panel simply stays open.
 *
 * Markup contract (see admin/views/media/media.php):
 *   <details data-lp-dropdown><summary>...</summary>...menu...</details>
 *   <details data-lp-media-folders open>...sidebar...</details>
 */
(function () {
    'use strict';

    // Matches the admin stylesheet's mobile breakpoint for the Media Manager.
    var NARROW_QUERY = '(max-width: 782px)';

    document.addEventListener('DOMContentLoaded', function () {
        var dropdowns = Array.prototype.slice.call(document.querySelectorAll('[data-lp-dropdown]'));

        function closeAll(except) {
            dropdowns.forEach(function (dropdown) {
                if (dropdown !== except && dropdown.open) {
                    dropdown.open = false;
                }
            });
        }

        dropdowns.forEach(function (dropdown) {
            dropdown.addEventListener('toggle', function () {
                if (dropdown.open) {
                    closeAll(dropdown);
                }
            });

            dropdown.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape' || !dropdown.open) {
                    return;
                }

                event.preventDefault();
                dropdown.open = false;
                dropdown.querySelector('summary').focus();
            });
        });

        if (dropdowns.length > 0) {
            document.addEventListener('click', function (event) {
                var inside = event.target.closest ? event.target.closest('[data-lp-dropdown]') : null;
                closeAll(inside);
            });
        }

        var folders = document.querySelector('[data-lp-media-folders]');

        if (folders) {
            var narrow = window.matchMedia(NARROW_QUERY);

            if (narrow.matches) {
                folders.open = false;
            }

            // The panel's summary is hidden at desktop widths, so it must
            // never be left closed there after a resize.
            narrow.addEventListener('change', function () {
                if (!narrow.matches) {
                    folders.open = true;
                }
            });
        }
    });
}());
