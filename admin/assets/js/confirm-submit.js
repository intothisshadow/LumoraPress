/**
 * Generic "confirm before submitting" enhancement (LP-069): replaces the
 * inline `onsubmit="return confirm('...');"` / `onclick="return
 * confirm('...');"` attributes that Lumora Press's default
 * Content-Security-Policy (`script-src 'self'`, no `'unsafe-inline'`, see
 * ContentSecurityPolicy::defaultDirectives()) silently blocks — the form
 * still submits, but the inline JS never runs, so destructive actions
 * (mostly deletes) previously had zero confirmation prompt.
 *
 * Markup contract:
 *   <form data-lp-confirm="Delete this page permanently?">...</form>
 * confirms once, on every submit of that form.
 *
 *   <button type="submit" data-lp-confirm="Reset every option?">...</button>
 * confirms only when that specific submit button is the one clicked,
 * for forms that contain more than one submit button with different
 * consequences (e.g. a "Save" button alongside a "Reset to Defaults"
 * button in the same form).
 *
 * A submit triggered by a button's own data-lp-confirm is not
 * double-confirmed by a form-level data-lp-confirm on the same form:
 * the click handler marks the event so the submit handler can tell it
 * already ran.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var button = event.target.closest('button[data-lp-confirm]');

        if (!button) {
            return;
        }

        if (!window.confirm(button.dataset.lpConfirm)) {
            event.preventDefault();
            return;
        }

        button.dataset.lpConfirmed = 'true';
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-lp-confirm')) {
            return;
        }

        var submitter = event.submitter;

        if (submitter instanceof HTMLButtonElement && submitter.dataset.lpConfirmed === 'true') {
            delete submitter.dataset.lpConfirmed;
            return;
        }

        if (!window.confirm(form.getAttribute('data-lp-confirm'))) {
            event.preventDefault();
        }
    });
}());
