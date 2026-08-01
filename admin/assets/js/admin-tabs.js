/**
 * Progressively enhances any `.lp-tabs` component (admin.css) with
 * click/keyboard tab switching. Without this script, each tab panel's
 * visibility is fixed by the `hidden` attribute rendered server-side, so
 * the page is still usable — this only adds the ability to switch tabs
 * without a page reload, following the WAI-ARIA tabs pattern (Left/Right
 * arrow keys move focus and activate).
 */
(function () {
    'use strict';

    function activate(tabs, panels, index) {
        tabs.forEach(function (tab, i) {
            var selected = i === index;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;
        });

        panels.forEach(function (panel, i) {
            panel.hidden = i !== index;
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.lp-tabs'), function (root) {
            var tabs = Array.prototype.slice.call(root.querySelectorAll('.lp-tabs__tab'));
            var panels = tabs.map(function (tab) {
                return document.getElementById(tab.getAttribute('aria-controls'));
            });

            if (tabs.length === 0 || panels.indexOf(null) !== -1) {
                return;
            }

            tabs.forEach(function (tab, index) {
                tab.addEventListener('click', function () {
                    activate(tabs, panels, index);
                });

                tab.addEventListener('keydown', function (event) {
                    var delta = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;

                    if (delta === 0) {
                        return;
                    }

                    event.preventDefault();
                    var nextIndex = (index + delta + tabs.length) % tabs.length;
                    tabs[nextIndex].focus();
                    activate(tabs, panels, nextIndex);
                });
            });
        });
    });
})();
