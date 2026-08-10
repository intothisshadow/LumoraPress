<?php

/**
 * Default theme footer template: closing HTML, footer navigation, and conditional third-party script tags.
 *
 * @package LumoraPress
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */
?>
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
    <?php
    /*
     * The "Show filenames in lightbox" setting reaches media-viewer.js
     * via a data-* attribute on this same <script> tag, not an inline
     * <script>— this project's Content-Security-Policy script-src has
     * no 'unsafe-inline'/nonce allowance (only style-src does, via
     * csp_style_nonce()), so an inline script here would be silently
     * blocked by every browser with no server-side error to catch it.
     * A data-* attribute on an externally-src'd <script> isn't inline
     * script execution, so CSP has no opinion on it.
     */
    $showFilenames = \LumoraPress\Core\Theme\FeaturedImages::config()->option('lightbox_show_filenames', '0') === '1';
    ?>
    <script type="module" data-lp-media-viewer data-show-filenames="<?= $showFilenames ? '1' : '0' ?>" src="<?= esc_url(theme_url('assets/js/media-viewer.js')) ?>"></script>
<?php endif; ?>
<?php if (\LumoraPress\Core\Theme\ScriptEmbeds::isUsed('twitter')): ?>
    <!--
        Twitter/X Auto-Embed (LP-070): widgets.js scans the page on load
        for <blockquote class="twitter-tweet"> markup (emitted by
        EmbedService::wrap()) and replaces each one with its own rendered
        iframe. Conditional on ScriptEmbeds::isUsed('twitter') — only set
        true when a post/page actually contained a matching tweet URL
        earlier in this same request — so a page with no tweet embeds
        loads nothing extra. One <script> tag covers every tweet on the
        page; widgets.js itself scans the whole DOM, so no per-embed
        loading is needed.
    -->
    <script async src="https://platform.twitter.com/widgets.js"></script>
<?php endif; ?>
<?php if (\LumoraPress\Core\Theme\ScriptEmbeds::isUsed('bluesky')): ?>
    <!--
        Bluesky Auto-Embed (LP-071): embed.js scans the page on load for
        <blockquote class="bluesky-embed"> markup (emitted by
        EmbedService::wrap()) and replaces each one with its own rendered
        iframe. Conditional on ScriptEmbeds::isUsed('bluesky') — only set
        true when a post/page actually contained a resolved Bluesky post
        URL earlier in this same request — so a page with no Bluesky
        embeds loads nothing extra.
    -->
    <script async src="https://embed.bsky.app/static/embed.js" charset="utf-8"></script>
<?php endif; ?>
</body>
</html>
