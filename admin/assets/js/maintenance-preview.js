/**
 * Maintenance Mode "Preview in New Tab" button.
 *
 * The button is a normal submit button with a formaction, which already
 * works on its own. This script instead posts the form's current values
 * from a separate throwaway form, which every browser handles the same
 * way, and keeps the settings form itself untouched.
 *
 * Markup contract: <button data-lp-maintenance-preview data-preview-url="...">
 * inside the settings <form>.
 */
(function () {
    'use strict';

    // Values that only matter to the Save handler, never to the preview.
    var SKIP = ['csrf_token', 'form'];

    document.querySelectorAll('[data-lp-maintenance-preview]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            var source = button.form;

            if (!source || !source.reportValidity()) {
                event.preventDefault();

                return;
            }

            event.preventDefault();

            var preview = document.createElement('form');
            preview.method = 'post';
            preview.action = button.getAttribute('data-preview-url');
            preview.target = '_blank';
            preview.hidden = true;

            new FormData(source).forEach(function (value, name) {
                if (SKIP.indexOf(name) !== -1 || typeof value !== 'string') {
                    return;
                }

                var field = document.createElement('input');
                field.type = 'hidden';
                field.name = name;
                field.value = value;
                preview.appendChild(field);
            });

            document.body.appendChild(preview);
            preview.submit();
            document.body.removeChild(preview);
        });
    });
})();
