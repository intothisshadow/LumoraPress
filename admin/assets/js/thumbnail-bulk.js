/**
 * Progressively enhances bulk thumbnail regeneration: without JavaScript,
 * the "Continue" button shown after each batch (see admin/views/media.php)
 * still works, it just has to be clicked once per batch. With JavaScript,
 * the same form auto-submits so the whole run completes unattended.
 *
 * Markup contract: a <form id="thumb-bulk-continue"> containing its own
 * submit button, rendered only while a bulk regeneration run is still in
 * progress.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('thumb-bulk-continue');

        if (form) {
            window.setTimeout(function () {
                form.requestSubmit();
            }, 400);
        }
    });
})();
