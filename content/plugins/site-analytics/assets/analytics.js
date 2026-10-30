/**
 * Site Analytics loader: reads its settings from this script tag's data-*
 * attributes and starts the chosen provider. Kept as a file (no inline code)
 * so it works under a Content-Security-Policy that blocks inline scripts.
 */
(function () {
    'use strict';

    var tag = document.currentScript;

    if (!tag) {
        return;
    }

    var data = tag.dataset;

    // Do Not Track and Global Privacy Control are read here, in the browser,
    // so a cached page still honors each visitor's own signal.
    if (data.respectDnt === '1') {
        var dnt = navigator.doNotTrack || window.doNotTrack || navigator.msDoNotTrack;

        if (dnt === '1' || dnt === 'yes' || navigator.globalPrivacyControl === true) {
            return;
        }
    }

    function load(src, attributes) {
        var script = document.createElement('script');
        script.async = true;
        script.src = src;

        Object.keys(attributes || {}).forEach(function (name) {
            script.setAttribute(name, attributes[name]);
        });

        document.head.appendChild(script);
    }

    switch (data.lpAnalytics) {
        case 'plausible':
            load(data.scriptUrl, { 'data-domain': data.domain, defer: '' });
            break;

        case 'fathom':
            load(data.scriptUrl, { 'data-site': data.siteId, defer: '' });
            break;

        case 'matomo':
            window._paq = window._paq || [];
            window._paq.push(['setTrackerUrl', data.trackerUrl + 'matomo.php']);
            window._paq.push(['setSiteId', data.siteId]);
            window._paq.push(['trackPageView']);
            window._paq.push(['enableLinkTracking']);
            load(data.trackerUrl + 'matomo.js');
            break;

        case 'ga4':
            window.dataLayer = window.dataLayer || [];
            window.gtag = function () {
                window.dataLayer.push(arguments);
            };
            window.gtag('js', new Date());
            window.gtag('config', data.measurementId);
            load('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(data.measurementId));
            break;
    }
})();
