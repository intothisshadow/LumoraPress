/**
 * Settings > Analytics: shows only the field group for the selected provider.
 * Without JavaScript every group stays visible, so nothing is unreachable.
 */
(function () {
    'use strict';

    var select = document.getElementById('analytics-provider');

    if (!select) {
        return;
    }

    var groups = document.querySelectorAll('[data-lp-analytics-provider]');

    function sync() {
        groups.forEach(function (group) {
            group.hidden = group.getAttribute('data-lp-analytics-provider') !== select.value;
        });
    }

    select.addEventListener('change', sync);
    sync();
})();
