</div>
<footer class="lp-site-footer">
    <div class="lp-site-footer__inner">
        <nav class="lp-site-footer__nav" aria-label="Footer">
            <?php nav_menu('footer'); ?>
        </nav>
        <p class="lp-site-footer__copyright">
            <?php if (footer_copyright_text() !== ''): ?>
                <?= esc_html(footer_copyright_text()) ?>
            <?php else: ?>
                &copy; <?= esc_html(date('Y')) ?> <?= esc_html(site_name()) ?>.
            <?php endif; ?>
        </p>
        <a class="lp-site-footer__feed-link" href="<?= esc_url(home_url('feed')) ?>">Subscribe via RSS</a>
    </div>
</footer>
<script src="<?= esc_url(theme_url('assets/js/dynamic-style.js')) ?>"></script>
<?php if (\LumoraPress\Core\Theme\MediaViewer::isUsed()): ?>
    <!--
        Media Viewer & Lightbox (LP-031): PhotoSwipe loaded from jsDelivr
        (per explicit request, no locally-vendored copy) at a pinned
        version so a future PhotoSwipe release can't silently change
        behavior here. Both the CSS and JS are conditional on
        MediaViewer::isUsed() — only set true when
        the_post_thumbnail_lightbox() actually rendered an image earlier
        in this same request — so a page with no images loads neither.
    -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/photoswipe@5.4.4/dist/photoswipe.css">
    <script type="module" src="<?= esc_url(theme_url('assets/js/media-viewer.js')) ?>"></script>
<?php endif; ?>
</body>
</html>
