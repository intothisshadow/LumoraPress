/**
 * Settings > Cache "Test Cache Headers" panel: requests the homepage without
 * cookies, then repeats the request with the returned ETag to confirm the
 * server answers 304. Results are written with textContent only, so header
 * values can never be interpreted as markup.
 *
 * Markup contract:
 *   <section data-lp-cache-test>
 *     <button data-lp-cache-test-run data-lp-cache-test-url="https://example.com/">,
 *     <p data-lp-cache-test-status>,
 *     <table data-lp-cache-test-results hidden><tbody></tbody></table>
 */
(function () {
    'use strict';

    var HEADERS = [
        ['Cache-Control', 'Cache-Control'],
        ['ETag', 'ETag'],
        ['Vary', 'Vary'],
        ['X-LiteSpeed-Cache', 'X-LiteSpeed-Cache'],
        ['Cache tags', 'X-Lumora-Cache-Tags']
    ];

    document.addEventListener('DOMContentLoaded', function () {
        var panel = document.querySelector('[data-lp-cache-test]');

        if (!panel) {
            return;
        }

        var button = panel.querySelector('[data-lp-cache-test-run]');
        var status = panel.querySelector('[data-lp-cache-test-status]');
        var table = panel.querySelector('[data-lp-cache-test-results]');
        var url = button.getAttribute('data-lp-cache-test-url');

        function addRow(label, value) {
            var row = document.createElement('tr');
            var th = document.createElement('th');
            var td = document.createElement('td');

            th.scope = 'row';
            th.textContent = label;
            td.textContent = value;
            row.appendChild(th);
            row.appendChild(td);
            table.querySelector('tbody').appendChild(row);
        }

        // cache: 'no-store' and no cookies, so the browser's own cache and
        // the admin's login can't change what the server returns.
        function request(extraHeaders) {
            return fetch(url, { cache: 'no-store', credentials: 'omit', headers: extraHeaders || {} });
        }

        button.addEventListener('click', function () {
            button.disabled = true;
            status.textContent = 'Testing…';
            table.querySelector('tbody').textContent = '';

            request().then(function (first) {
                addRow('Status', String(first.status));

                HEADERS.forEach(function (header) {
                    var value = first.headers.get(header[1]);

                    addRow(header[0], value === null ? 'Not sent' : value);
                });

                var etag = first.headers.get('ETag');

                if (etag === null) {
                    addRow('Conditional request', 'Skipped: the page was sent without an ETag, so it is not being cached.');

                    return null;
                }

                return request({ 'If-None-Match': etag }).then(function (second) {
                    addRow('Conditional request', second.status === 304 ? '304 Not Modified (working)' : 'Answered ' + second.status + ' instead of 304');
                });
            }).then(function () {
                table.hidden = false;
                status.textContent = 'Test complete.';
            }).catch(function () {
                status.textContent = 'The test could not reach your homepage.';
            }).then(function () {
                button.disabled = false;
            });
        });
    });
}());
