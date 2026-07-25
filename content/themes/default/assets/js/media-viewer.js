/**
 * Media Viewer & Lightbox (LP-031). Progressively enhances every
 * `.lp-gallery` container's `<a data-pswp-width="..." data-pswp-height="...">`
 * children (rendered by the_post_thumbnail_lightbox(), see
 * include/media-functions.php) into a PhotoSwipe lightbox with
 * prev/next navigation across that container's images. Without this
 * script, those anchors are still plain, visible links to the full-size
 * image — nothing is hidden or broken, just not lightboxed.
 *
 * This file is only ever loaded (see footer.php) when
 * MediaViewer::isUsed() was set — i.e. at least one image was actually
 * rendered — so it never runs on a page with nothing to show.
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
