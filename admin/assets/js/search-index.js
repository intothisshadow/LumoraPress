/**
 * Runs Settings > Search's "Rebuild Search Index" one step per request, so
 * progress shows in place and no single request has to outlast a slow
 * table rebuild. Mirrors media-import-continue.js's approach.
 *
 * Markup contract: <form id="search-index-rebuild"> with hidden `stage`
 * and `csrf_token` fields, plus [data-lp-search-index-*] progress and
 * status elements. Without JavaScript the form still works: the server
 * runs every step in one request and redirects.
 */
(function () {
    'use strict';

    function drive(form) {
        var stageField = form.querySelector('input[name="stage"]');
        var tokenField = form.querySelector('input[name="csrf_token"]');
        var button = form.querySelector('button[type="submit"]');
        var barWrap = document.querySelector('[data-lp-search-index-progress-wrap]');
        var bar = document.querySelector('[data-lp-search-index-progress-bar]');
        var status = document.querySelector('[data-lp-search-index-status]');
        var firstStage = stageField.value;

        function show(message) {
            if (status) {
                status.textContent = message;
            }
        }

        function fail(message) {
            show(message);
            stageField.value = firstStage;
            button.disabled = false;
        }

        function tick() {
            fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'fetch' },
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    var data = result.data;

                    if (!result.ok || data.error) {
                        fail(data.error || 'The search index could not be rebuilt.');

                        return;
                    }

                    if (bar && typeof data.percent === 'number') {
                        bar.style.width = data.percent + '%';
                        barWrap.setAttribute('aria-valuenow', String(data.percent));
                    }

                    if (data.done) {
                        show('Done.');
                        window.location.href = data.redirect;

                        return;
                    }

                    show(data.label + '…');
                    stageField.value = data.next;
                    // The token is single-use, so each round needs the fresh one.
                    tokenField.value = data.csrf_token;
                    tick();
                })
                .catch(function () {
                    fail('The connection was interrupted. Reload the page and try again.');
                });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            button.disabled = true;

            if (barWrap) {
                barWrap.hidden = false;
            }

            show('Rebuilding the post index…');
            tick();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('search-index-rebuild');

        if (form && typeof fetch === 'function') {
            drive(form);
        }
    });
}());
