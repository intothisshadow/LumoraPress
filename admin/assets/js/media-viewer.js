/**
 * Media Viewer & Lightbox (LP-031), admin copy. Progressively enhances
 * every `.lp-gallery` container's `<a data-pswp-width="..." data-pswp-height="...">`
 * children into a PhotoSwipe lightbox — used on the Media Manager's
 * image edit/preview view (admin/views/media.php). Deliberately a
 * separate copy from content/themes/default/assets/js/media-viewer.js
 * rather than the admin depending on whatever theme happens to be
 * active. Only loaded (see admin/views/layout-footer.php) when
 * MediaViewer::isUsed() was set.
 *
 * PhotoSwipe itself loads from jsDelivr at a pinned version, not
 * vendored locally, per explicit project decision.
 */
(function () {
    'use strict';

    var PHOTOSWIPE_VERSION = '5.4.4';
    var LIGHTBOX_URL = 'https://cdn.jsdelivr.net/npm/photoswipe@' + PHOTOSWIPE_VERSION + '/dist/photoswipe-lightbox.esm.min.js';
    var CORE_URL = 'https://cdn.jsdelivr.net/npm/photoswipe@' + PHOTOSWIPE_VERSION + '/dist/photoswipe.esm.min.js';

    document.addEventListener('DOMContentLoaded', function () {
        var galleries = document.querySelectorAll('.lp-gallery');

        if (!galleries.length) {
            return;
        }

        import(LIGHTBOX_URL).then(function (module) {
            var PhotoSwipeLightbox = module.default;

            galleries.forEach(function (gallery) {
                var lightbox = new PhotoSwipeLightbox({
                    gallery: gallery,
                    children: 'a[data-pswp-width]',
                    pswpModule: function () {
                        return import(CORE_URL);
                    },
                });

                lightbox.on('uiRegister', function () {
                    lightbox.pswp.ui.registerElement({
                        name: 'lp-caption',
                        appendTo: 'root',
                        order: 9,
                        isButton: false,
                        html: '',
                        onInit: function (el, pswp) {
                            el.className = 'lp-pswp-caption';
                            pswp.on('change', function () {
                                var caption = pswp.currSlide
                                    && pswp.currSlide.data
                                    && pswp.currSlide.data.element
                                    && pswp.currSlide.data.element.dataset.pswpCaption;
                                el.textContent = caption || '';
                                el.style.display = caption ? 'block' : 'none';
                            });
                        },
                    });
                });

                lightbox.init();
            });
        });
    });
})();
