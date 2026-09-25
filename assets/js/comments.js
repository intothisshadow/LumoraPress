/**
 * Progressive enhancement for comment threads: Quote buttons, and
 * reactions/reports that update in place instead of reloading the page.
 *
 * Without this script the reaction and report forms still work as plain
 * form posts (the Quote button just stays hidden). Requests send
 * "X-Requested-With: fetch" so CommentInteractionController answers with
 * JSON, including a fresh CSRF token: the server consumes the page's token
 * on every successful check, so each response's token is copied into every
 * reaction/report form on the page for the next click.
 */
(function () {
    'use strict';

    function refreshTokens(token) {
        if (typeof token !== 'string' || token === '') {
            return;
        }

        document.querySelectorAll('[data-lp-comment-csrf]').forEach(function (input) {
            input.value = token;
        });
    }

    function post(form, submitter) {
        var data = new FormData(form);

        if (submitter && submitter.name) {
            data.append(submitter.name, submitter.value);
        }

        return fetch(form.action, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Request failed');
            }

            return response.json();
        }).then(function (payload) {
            refreshTokens(payload.csrf_token);

            return payload;
        });
    }

    // Plain submit() skips the clicked button's name/value, so carry it over by hand.
    function fallbackSubmit(form, submitter) {
        if (submitter && submitter.name) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = submitter.name;
            input.value = submitter.value;
            form.appendChild(input);
        }

        form.submit();
    }

    function updateReactions(form, payload) {
        var counts = payload.counts || {};

        form.querySelectorAll('[data-lp-reaction]').forEach(function (button) {
            var key = button.getAttribute('data-lp-reaction');
            var count = counts[key] || 0;
            var countEl = button.querySelector('[data-lp-reaction-count]');
            var selected = payload.choice === key;
            var label = button.getAttribute('data-lp-reaction-label') || key;

            if (countEl) {
                countEl.textContent = String(count);
                countEl.hidden = count === 0;
            }

            button.classList.toggle('is-selected', selected);
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
            button.setAttribute('aria-label', count > 0 ? label + ' (' + count + ')' : label);
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (form.hasAttribute('data-lp-comment-reactions')) {
            event.preventDefault();
            form.setAttribute('aria-busy', 'true');

            post(form, event.submitter).then(function (payload) {
                if (payload.ok) {
                    updateReactions(form, payload);
                }
            }).catch(function () {
                fallbackSubmit(form, event.submitter);
            }).finally(function () {
                form.removeAttribute('aria-busy');
            });
        } else if (form.hasAttribute('data-lp-comment-report')) {
            event.preventDefault();

            post(form, event.submitter).then(function (payload) {
                if (!payload.ok) {
                    return;
                }

                var details = form.closest('details');
                var thanks = document.createElement('p');
                thanks.className = 'lp-comment-report__thanks';
                thanks.setAttribute('role', 'status');
                thanks.textContent = form.getAttribute('data-lp-report-thanks') || '';
                (details || form).replaceWith(thanks);
            }).catch(function () {
                fallbackSubmit(form, event.submitter);
            });
        }
    });

    function quote(button) {
        var target = document.getElementById(button.getAttribute('data-quote-target') || '');

        if (!target) {
            return;
        }

        var author = button.getAttribute('data-quote-author') || '';
        var text = button.getAttribute('data-quote-text') || '';
        var quoted = [author + ':'].concat(text.split(/\r?\n/)).map(function (line) {
            return '> ' + line;
        }).join('\n');

        var details = target.closest('details');

        if (details) {
            details.open = true;
        }

        target.value = (target.value.trim() !== '' ? target.value.replace(/\s+$/, '') + '\n\n' : '') + quoted + '\n\n';
        target.focus();
        target.setSelectionRange(target.value.length, target.value.length);
    }

    document.querySelectorAll('[data-lp-comment-quote]').forEach(function (button) {
        button.hidden = false;
        button.addEventListener('click', function () {
            quote(button);
        });
    });
}());
