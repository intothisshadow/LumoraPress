/**
 * Progressively enhances batch-per-request admin flows (bulk thumbnail
 * regeneration, LP-001; server media import, LP-041; Visitor Stats'
 * GeoLite2 CSV import, LPP-014): without JavaScript, the "Continue"
 * button shown after each batch (see admin/views/media.php) still
 * works, it just has to be clicked once per batch. With JavaScript, the
 * same form auto-submits so the whole run completes unattended.
 *
 * Markup contract: a <form id="thumb-bulk-continue">,
 * <form id="import-bulk-continue">, or <form id="geoip-import-continue">
 * containing its own submit button, rendered only while that particular
 * run is still in progress.
 */
(function () {
    'use strict';

    var CONTINUE_FORM_IDS = ['thumb-bulk-continue', 'import-bulk-continue', 'geoip-import-continue'];

    document.addEventListener('DOMContentLoaded', function () {
        CONTINUE_FORM_IDS.forEach(function (id) {
            var form = document.getElementById(id);

            if (form) {
                window.setTimeout(function () {
                    form.requestSubmit();
                }, 400);
            }
        });
    });
})();
