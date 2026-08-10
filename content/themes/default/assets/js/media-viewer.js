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
 * rendered — so it never runs on a page with nothing to show. footer.php
 * also puts the site's "show filenames in lightbox" setting on this
 * script tag's own data-show-filenames attribute (found via
 * data-lp-media-viewer, a stable marker — this is a module script, so
 * document.currentScript is never available) — not an inline <script>,
 * since this project's CSP script-src has no inline-execution allowance.
 *
 * PhotoSwipe itself loads from jsDelivr at a pinned version, not
 * vendored locally, per explicit project decision.
 */
(function () {
    'use strict';

    var PHOTOSWIPE_VERSION = '5.4.4';
    var LIGHTBOX_URL = 'https://cdn.jsdelivr.net/npm/photoswipe@' + PHOTOSWIPE_VERSION + '/dist/photoswipe-lightbox.esm.min.js';
    var CORE_URL = 'https://cdn.jsdelivr.net/npm/photoswipe@' + PHOTOSWIPE_VERSION + '/dist/photoswipe.esm.min.js';
    var SLIDESHOW_INTERVAL_MS = 4000;
    var DEEP_LINK_PREFIX = '#lp-media-';

    function currentSlideElement(pswp) {
        return pswp.currSlide && pswp.currSlide.data && pswp.currSlide.data.element;
    }

    function clearDeepLinkHash() {
        if (window.location.hash.indexOf(DEEP_LINK_PREFIX) === 0) {
            history.replaceState(null, '', window.location.pathname + window.location.search);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var galleries = document.querySelectorAll('.lp-gallery');

        if (!galleries.length) {
            return;
        }

        var configScript = document.querySelector('script[data-lp-media-viewer]');
        var showFilenames = !!(configScript && configScript.dataset.showFilenames === '1');
        var deepLinkHash = window.location.hash;

        import(LIGHTBOX_URL).then(function (module) {
            var PhotoSwipeLightbox = module.default;

            galleries.forEach(function (gallery) {
                var lightbox = new PhotoSwipeLightbox({
                    gallery: gallery,
                    children: 'a[data-pswp-width], a[data-pswp-lightbox]',
                    pswpModule: function () {
                        return import(CORE_URL);
                    },
                });

                var slideshowTimer = null;

                var stopSlideshow = function (buttonEl) {
                    if (slideshowTimer) {
                        clearInterval(slideshowTimer);
                        slideshowTimer = null;
                    }
                    if (buttonEl) {
                        buttonEl.classList.remove('lp-pswp-slideshow--playing');
                    }
                };

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
                                var element = currentSlideElement(pswp);
                                var caption = element && element.dataset.pswpCaption;
                                el.textContent = caption || '';
                                el.style.display = caption ? 'block' : 'none';
                            });
                        },
                    });

                    // Image dimensions + optional filename (site setting).
                    lightbox.pswp.ui.registerElement({
                        name: 'lp-meta',
                        appendTo: 'root',
                        order: 10,
                        isButton: false,
                        html: '',
                        onInit: function (el, pswp) {
                            el.className = 'lp-pswp-meta';
                            pswp.on('change', function () {
                                var element = currentSlideElement(pswp);
                                var parts = [];

                                if (element) {
                                    var width = element.dataset.pswpWidth;
                                    var height = element.dataset.pswpHeight;

                                    if (width && height) {
                                        parts.push(width + ' × ' + height);
                                    }

                                    if (showFilenames && element.dataset.pswpFilename) {
                                        parts.push(element.dataset.pswpFilename);
                                    }
                                }

                                el.textContent = parts.join(' — ');
                                el.style.display = parts.length ? 'block' : 'none';
                            });
                        },
                    });

                    // Download button — downloads the full-size image the
                    // lightbox is currently showing, not a thumbnail.
                    lightbox.pswp.ui.registerElement({
                        name: 'lp-download',
                        ariaLabel: 'Download image',
                        order: 8,
                        isButton: true,
                        // No fill="..." on the <path> — PhotoSwipe's own
                        // .pswp__icn class sets fill: var(--pswp-icon-color)
                        // (white), inherited by an unset child <path>. An
                        // explicit fill="currentColor" here (this file's
                        // original mistake) instead resolves against
                        // .pswp__icn's `color` property
                        // (--pswp-icon-color-secondary, a dark grey meant
                        // for icon shadows/outlines, not fills), rendering
                        // the icon nearly invisible against the dark
                        // toolbar.
                        html: '<svg aria-hidden="true" class="pswp__icn" viewBox="0 0 24 24" width="24" height="24"><path d="M12 16l-6-6h4V4h4v6h4l-6 6zM5 18h14v2H5z"/></svg>',
                        onClick: function (event, el, pswp) {
                            var element = currentSlideElement(pswp);
                            var href = element && element.getAttribute('href');

                            if (!href) {
                                return;
                            }

                            var link = document.createElement('a');
                            link.href = href;
                            link.download = (element && element.dataset.pswpFilename) || '';
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                        },
                    });

                    // Slideshow toggle — advances to the next slide every
                    // SLIDESHOW_INTERVAL_MS until toggled off, the lightbox
                    // is closed, or the visitor navigates manually (which
                    // PhotoSwipe's own 'change' event can't distinguish
                    // from an auto-advance, so manual navigation simply
                    // resets the interval rather than stopping it).
                    lightbox.pswp.ui.registerElement({
                        name: 'lp-slideshow',
                        ariaLabel: 'Toggle slideshow',
                        order: 7,
                        isButton: true,
                        // See lp-download's identical comment above for
                        // why there's no fill="currentColor" here.
                        html: '<svg aria-hidden="true" class="pswp__icn" viewBox="0 0 24 24" width="24" height="24"><path d="M8 5v14l11-7z"/></svg>',
                        onClick: function (event, el, pswp) {
                            if (slideshowTimer) {
                                stopSlideshow(el);
                            } else {
                                slideshowTimer = setInterval(function () {
                                    pswp.next();
                                }, SLIDESHOW_INTERVAL_MS);
                                el.classList.add('lp-pswp-slideshow--playing');
                            }
                        },
                    });
                });

                // Deep-link support: reflect the open image in the URL
                // hash (history.replaceState, not pushState, so paging
                // through a gallery doesn't spam browser history) and
                // clear it again on close, so a reload of a copied link
                // reopens the same image via the loadAndOpen() call below.
                lightbox.on('change', function () {
                    var element = currentSlideElement(lightbox.pswp);
                    var id = element && element.dataset.pswpId;

                    if (id) {
                        history.replaceState(null, '', DEEP_LINK_PREFIX + id);
                    }
                });

                lightbox.on('close', function () {
                    stopSlideshow(null);
                    clearDeepLinkHash();
                });

                lightbox.init();

                if (deepLinkHash.indexOf(DEEP_LINK_PREFIX) === 0) {
                    var targetId = deepLinkHash.slice(DEEP_LINK_PREFIX.length);
                    var items = gallery.querySelectorAll('a[data-pswp-width], a[data-pswp-lightbox]');

                    for (var i = 0; i < items.length; i++) {
                        if (items[i].dataset.pswpId === targetId) {
                            lightbox.loadAndOpen(i, { gallery: gallery });
                            break;
                        }
                    }
                }
            });
        });
    });
})();
