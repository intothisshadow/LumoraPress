/**
 * LP-151: positions and keyboard-closes the collapsed sidebar's flyout
 * submenus (admin/views/layout-header.php, admin.css). The flyout itself
 * is CSS-driven (:hover/:focus-within), but it's `position: fixed` so it
 * can escape .lp-admin__nav's own scroll clipping — see admin.css's
 * comment on the flyout rule — and fixed positioning has no way to align
 * itself with its trigger row without JS reading that row's actual
 * position.
 */
(function () {
    'use strict';

    function positionFlyout(item) {
        var submenu = item.querySelector(':scope > .lp-admin__nav-submenu');

        if (!submenu) {
            return;
        }

        submenu.style.top = item.getBoundingClientRect().top + 'px';
    }

    document.addEventListener('DOMContentLoaded', function () {
        var parents = Array.prototype.slice.call(document.querySelectorAll('.lp-admin__nav-item--parent'));

        parents.forEach(function (item) {
            item.addEventListener('mouseenter', function () {
                positionFlyout(item);
            });
            item.addEventListener('focusin', function () {
                positionFlyout(item);
            });
        });
    });

    // Closing on Escape returns focus to the parent link — CSS's
    // :focus-within already closes the flyout as soon as focus leaves it,
    // this just gives keyboard users an explicit way to do that instead
    // of tabbing all the way through.
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        var activeItem = document.activeElement && document.activeElement.closest('.lp-admin__nav-item--parent');

        if (!activeItem) {
            return;
        }

        var trigger = activeItem.querySelector('a');

        if (trigger) {
            trigger.focus();
        }
    });
})();
