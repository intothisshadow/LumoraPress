/**
 * Runs a Sweep clean-up in batches (Maintenance > Tools > Sweep), updating a progress bar in
 * place instead of reloading the page for every batch.
 *
 * Markup contract: a <form data-lp-sweep-batch data-lp-sweep-total="N" data-lp-sweep-confirm="...">
 * with a hidden `csrf_token` field, followed by a sibling [data-lp-sweep-progress] block holding a
 * <progress data-lp-sweep-bar> and a [data-lp-sweep-status] element. The server answers each POST
 * that carries `X-Requested-With: fetch` with { processed, remaining, done, csrf_token }.
 * Without JavaScript the form submits normally and the server handles one batch.
 *
 * The confirmation is asked here rather than through data-lp-confirm, because a form's own
 * listener runs before the document-level confirm handler and would otherwise start first.
 */
(function () {
    'use strict';

    var NEXT_BATCH_DELAY_MS = 150;

    function run(form) {
        var progress = form.parentNode.querySelector('[data-lp-sweep-progress]');
        var bar = progress ? progress.querySelector('[data-lp-sweep-bar]') : null;
        var status = progress ? progress.querySelector('[data-lp-sweep-status]') : null;
        var tokenField = form.querySelector('input[name="csrf_token"]');
        var button = form.querySelector('button[type="submit"]');
        var total = parseInt(form.getAttribute('data-lp-sweep-total') || '0', 10);
        var handled = 0;

        if (button) {
            button.disabled = true;
        }

        if (progress) {
            progress.hidden = false;
        }

        function show(text) {
            if (status) {
                status.textContent = text;
            }
        }

        function finish() {
            window.location.reload();
        }

        function tick() {
            fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' },
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Unexpected response status: ' + response.status);
                    }

                    return response.json();
                })
                .then(function (data) {
                    handled += data.processed;

                    if (bar) {
                        bar.max = Math.max(total, handled + data.remaining, 1);
                        bar.value = handled;
                    }

                    show(handled.toLocaleString() + ' done, ' + data.remaining.toLocaleString() + ' left.');

                    if (data.done) {
                        show('Finished: ' + handled.toLocaleString() + ' handled. Reloading the counts…');
                        window.setTimeout(finish, 600);

                        return;
                    }

                    // Tokens are single-use, so each answer carries the next one.
                    if (data.csrf_token && tokenField) {
                        tokenField.value = data.csrf_token;
                    }

                    window.setTimeout(tick, NEXT_BATCH_DELAY_MS);
                })
                .catch(function () {
                    show('Something went wrong after ' + handled.toLocaleString() + '. Reload the page to see what is left.');

                    if (button) {
                        button.disabled = false;
                    }
                });
        }

        tick();
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof fetch !== 'function') {
            return;
        }

        var forms = document.querySelectorAll('form[data-lp-sweep-batch]');

        Array.prototype.forEach.call(forms, function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();

                var message = form.getAttribute('data-lp-sweep-confirm');

                if (message && !window.confirm(message)) {
                    return;
                }

                run(form);
            });
        });
    });
}());
