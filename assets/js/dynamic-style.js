/**
 * Applies per-request dynamic style values (e.g. the Tag Cloud widget's
 * per-tag computed font size) that can't be expressed as a static CSS
 * class, without resorting to inline style="..." attributes — those are
 * blocked by the site's CSP style-src policy
 * (ContentSecurityPolicy::defaultDirectives(),
 * app/Core/Security/ContentSecurityPolicy.php), which has no
 * 'unsafe-inline' and no attribute-level nonce support (nonces only cover
 * <style> elements). Mirrors admin/assets/js/dynamic-style.js's pattern.
 *
 * Markup contract: any element carrying a `data-style-*` attribute gets
 * the matching CSS property set from that attribute's literal value, e.g.
 * data-style-font-size="1.4em" -> element.style.fontSize = "1.4em".
 */
(function () {
    'use strict';

    var ATTRIBUTE_PROPERTIES = {
        'data-style-font-size': 'fontSize',
        'data-style-width': 'width',
        'data-style-height': 'height',
    };

    // An author-chosen image size must be `auto` or a whole number with px or %;
    // anything else is ignored rather than handed to the CSS parser.
    var SIZE_PATTERN = /^(?:auto|[1-9][0-9]{0,3}(?:px|%))$/;
    var SIZE_PROPERTIES = { width: true, height: true };

    document.addEventListener('DOMContentLoaded', function () {
        Object.keys(ATTRIBUTE_PROPERTIES).forEach(function (attribute) {
            var property = ATTRIBUTE_PROPERTIES[attribute];

            document.querySelectorAll('[' + attribute + ']').forEach(function (el) {
                var value = el.getAttribute(attribute);

                if (SIZE_PROPERTIES[property] && !SIZE_PATTERN.test(value)) {
                    return;
                }

                el.style[property] = value;
            });
        });
    });
})();
