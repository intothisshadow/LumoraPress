/**
 * Applies per-request dynamic style values (a progress bar's width, a menu
 * item's indent depth) that can't be expressed as a static CSS class,
 * without resorting to inline style="..." attributes — those are blocked
 * by the admin's CSP style-src policy (ContentSecurityPolicy::defaultDirectives(),
 * app/Core/Security/ContentSecurityPolicy.php), which has no 'unsafe-inline'
 * and no attribute-level nonce support (nonces only cover <style> elements).
 *
 * Markup contract: any element carrying a `data-style-*` attribute gets the
 * matching CSS property set from that attribute's literal value, e.g.
 * data-style-width="42%" -> element.style.width = "42%".
 */
(function () {
    'use strict';

    var ATTRIBUTE_PROPERTIES = {
        'data-style-width': 'width',
        'data-style-margin-left': 'marginLeft',
    };

    document.addEventListener('DOMContentLoaded', function () {
        Object.keys(ATTRIBUTE_PROPERTIES).forEach(function (attribute) {
            var property = ATTRIBUTE_PROPERTIES[attribute];

            document.querySelectorAll('[' + attribute + ']').forEach(function (el) {
                el.style[property] = el.getAttribute(attribute);
            });
        });
    });
})();
