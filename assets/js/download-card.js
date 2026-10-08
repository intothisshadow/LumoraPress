/**
 * Progressive enhancement for download cards (Downloads plugin).
 *
 * Without this script a card's images are a horizontally scrolling, snapping row and its
 * description scrolls on its own, so everything still works. This adds, only where they help:
 *   - previous/next buttons and an "Image 2 of 5" status on a carousel with more than one image,
 *   - arrow-key, Home and End navigation on the focused carousel,
 *   - keyboard focus on a description only when it really overflows.
 *
 * Nothing moves by itself, and smooth scrolling is skipped when the visitor prefers reduced motion.
 */
(function () {
    'use strict';

    function prefersReducedMotion() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function button(label, direction) {
        var element = document.createElement('button');
        element.type = 'button';
        element.className = 'lp-download-card__nav lp-download-card__nav--' + direction;
        element.setAttribute('aria-label', label);
        element.innerHTML = direction === 'prev' ? '&#8249;' : '&#8250;';

        return element;
    }

    function enhanceCarousel(root) {
        var track = root.querySelector('[data-lp-carousel-track]');
        var slides = root.querySelectorAll('[data-lp-carousel-slide]');

        if (!track || slides.length < 2 || root.dataset.lpCarouselReady === '1') {
            return;
        }

        root.dataset.lpCarouselReady = '1';
        root.classList.add('is-enhanced');

        var previous = button('Previous image', 'prev');
        var next = button('Next image', 'next');
        var status = document.createElement('p');
        status.className = 'lp-download-card__status';
        status.setAttribute('aria-live', 'polite');

        root.appendChild(previous);
        root.appendChild(next);
        root.appendChild(status);

        function currentIndex() {
            return Math.max(0, Math.min(slides.length - 1, Math.round(track.scrollLeft / track.clientWidth)));
        }

        function update() {
            var index = currentIndex();
            status.textContent = 'Image ' + (index + 1) + ' of ' + slides.length;
            previous.disabled = index === 0;
            next.disabled = index === slides.length - 1;
        }

        function goTo(index) {
            var target = Math.max(0, Math.min(slides.length - 1, index));

            track.scrollTo({ left: target * track.clientWidth, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
        }

        previous.addEventListener('click', function () { goTo(currentIndex() - 1); });
        next.addEventListener('click', function () { goTo(currentIndex() + 1); });

        track.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft') {
                goTo(currentIndex() - 1);
            } else if (event.key === 'ArrowRight') {
                goTo(currentIndex() + 1);
            } else if (event.key === 'Home') {
                goTo(0);
            } else if (event.key === 'End') {
                goTo(slides.length - 1);
            } else {
                return;
            }

            event.preventDefault();
        });

        var frame = null;
        track.addEventListener('scroll', function () {
            if (frame !== null) {
                return;
            }

            frame = window.requestAnimationFrame(function () {
                frame = null;
                update();
            });
        }, { passive: true });

        window.addEventListener('resize', update);
        update();
    }

    // A scrollable region must be reachable from the keyboard, but only when there is something to scroll.
    function markScrollRegion(region) {
        var scrolls = region.scrollHeight > region.clientHeight + 1;

        if (scrolls) {
            var title = region.closest('.lp-download-card') && region.closest('.lp-download-card').querySelector('.lp-download-card__title');

            region.setAttribute('tabindex', '0');
            region.setAttribute('role', 'region');

            if (!region.hasAttribute('aria-label') && title) {
                region.setAttribute('aria-label', 'Description of ' + title.textContent);
            }

            region.classList.add('is-scrollable');
        } else {
            region.removeAttribute('tabindex');
            region.removeAttribute('role');
            region.removeAttribute('aria-label');
            region.classList.remove('is-scrollable');
        }
    }

    function init() {
        document.querySelectorAll('[data-lp-carousel]').forEach(enhanceCarousel);
        document.querySelectorAll('[data-lp-scroll-region]').forEach(markScrollRegion);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.addEventListener('load', function () {
        document.querySelectorAll('[data-lp-scroll-region]').forEach(markScrollRegion);
    });
})();
