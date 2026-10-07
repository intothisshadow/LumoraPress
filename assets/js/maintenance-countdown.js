/**
 * Live countdown on the maintenance page, for any element carrying
 * data-lp-countdown-to="<ISO 8601 timestamp>".
 *
 * The page's static "we expect to be back around..." text stays in place
 * for visitors without JavaScript. When the countdown reaches zero the
 * page reloads once, after a short random delay so a crowd of waiting
 * visitors doesn't hit the server in the same instant.
 */
(function () {
    'use strict';

    var elements = document.querySelectorAll('[data-lp-countdown-to]');

    if (!elements.length) {
        return;
    }

    function pad(value) {
        return value < 10 ? '0' + value : String(value);
    }

    function format(secondsLeft) {
        var days = Math.floor(secondsLeft / 86400);
        var hours = Math.floor((secondsLeft % 86400) / 3600);
        var minutes = Math.floor((secondsLeft % 3600) / 60);
        var seconds = secondsLeft % 60;
        var parts = [];

        if (days > 0) {
            parts.push(days + (days === 1 ? ' day' : ' days'));
        }

        parts.push(pad(hours) + ':' + pad(minutes) + ':' + pad(seconds));

        return parts.join(' ');
    }

    var reloadScheduled = false;

    function tick() {
        var now = Date.now();
        var anyRunning = false;

        elements.forEach(function (element) {
            var target = Date.parse(element.getAttribute('data-lp-countdown-to'));

            if (isNaN(target)) {
                return;
            }

            var secondsLeft = Math.max(0, Math.round((target - now) / 1000));
            var label = element.getAttribute('data-lp-countdown-label') || 'Back in';

            element.textContent = secondsLeft > 0 ? label + ' ' + format(secondsLeft) : 'We should be back any moment now.';
            element.hidden = false;

            if (secondsLeft > 0) {
                anyRunning = true;
            }
        });

        if (anyRunning) {
            window.setTimeout(tick, 1000);
        } else if (!reloadScheduled) {
            reloadScheduled = true;
            window.setTimeout(function () {
                window.location.reload();
            }, 2000 + Math.random() * 8000);
        }
    }

    tick();
})();
