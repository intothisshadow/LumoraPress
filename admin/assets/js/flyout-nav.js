/**
 * LP-151: positions and opens/closes the collapsed sidebar's flyout
 * submenus (admin/views/layout-header.php, admin.css). The flyout is
 * `position: fixed` so it can escape .lp-admin__nav's own scroll clipping
 * (see admin.css's comment on the flyout rule) — but that also means it's
 * no longer rendered inside the trigger row's own box, so the pointer
 * crosses genuinely empty space moving from one to the other. Relying on
 * pure CSS `:hover` for visibility closed the flyout the instant the
 * pointer left the trigger row, before it ever reached the flyout —
 * reported directly by Ariane. This adds a short close delay (and treats
 * the trigger row and the flyout as one hover group) so a normal, not
 * perfectly straight-line mouse movement across that gap still works.
 * `:focus-within` (admin.css) has no equivalent gap problem — focus moves
 * discretely between elements, not through physical space — so keyboard
 * use is unaffected and unchanged here.
 */
(function () {
    'use strict';

    var CLOSE_DELAY_MS = 300;

    function positionFlyout(item) {
        var submenu = item.querySelector(':scope > .lp-admin__nav-submenu');

        if (!submenu) {
            return null;
        }

        submenu.style.top = item.getBoundingClientRect().top + 'px';

        return submenu;
    }

    function open(item) {
        if (item.lpFlyoutCloseTimer) {
            clearTimeout(item.lpFlyoutCloseTimer);
            item.lpFlyoutCloseTimer = null;
        }

        positionFlyout(item);
        item.classList.add('lp-flyout-open');
    }

    function scheduleClose(item) {
        if (item.lpFlyoutCloseTimer) {
            clearTimeout(item.lpFlyoutCloseTimer);
        }

        item.lpFlyoutCloseTimer = setTimeout(function () {
            item.classList.remove('lp-flyout-open');
            item.lpFlyoutCloseTimer = null;
        }, CLOSE_DELAY_MS);
    }

    function closeNow(item) {
        if (item.lpFlyoutCloseTimer) {
            clearTimeout(item.lpFlyoutCloseTimer);
            item.lpFlyoutCloseTimer = null;
        }

        item.classList.remove('lp-flyout-open');
    }

    document.addEventListener('DOMContentLoaded', function () {
        var parents = Array.prototype.slice.call(document.querySelectorAll('.lp-admin__nav-item--parent'));

        parents.forEach(function (item) {
            var submenu = item.querySelector(':scope > .lp-admin__nav-submenu');

            item.addEventListener('mouseenter', function () {
                open(item);
            });
            item.addEventListener('mouseleave', function () {
                scheduleClose(item);
            });
            item.addEventListener('focusin', function () {
                open(item);
            });

            if (submenu) {
                // Bound on the flyout itself too, since it's visually
                // disjoint from `item` (see file docblock) — without this,
                // reaching the flyout during its close delay would do
                // nothing to cancel the pending close, and it would vanish
                // out from under the pointer anyway.
                submenu.addEventListener('mouseenter', function () {
                    open(item);
                });
                submenu.addEventListener('mouseleave', function () {
                    scheduleClose(item);
                });
            }
        });
    });

    // Closing on Escape returns focus to the parent link — CSS's
    // :focus-within already closes the flyout as soon as focus leaves it,
    // this just gives keyboard users an explicit way to do that instead
    // of tabbing all the way through. Also clears the mouse-driven open
    // state/timer so a flyout opened by mouse, then dismissed via Escape
    // while it happens to still have keyboard focus, doesn't hang around.
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        var activeItem = document.activeElement && document.activeElement.closest('.lp-admin__nav-item--parent');

        if (!activeItem) {
            return;
        }

        closeNow(activeItem);

        var trigger = activeItem.querySelector('a');

        if (trigger) {
            trigger.focus();
        }
    });
})();
