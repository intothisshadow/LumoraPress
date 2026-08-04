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
