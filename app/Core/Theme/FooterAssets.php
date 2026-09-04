<?php

/**
 * Emits the conditional PhotoSwipe/Plyr/Twitter/Bluesky <link>/<script> tags a theme's footer.php needs, via the 'footer_assets' hook.
 *
 * @package LumoraPress
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

/**
 * Emits PhotoSwipe, Plyr, and Auto-Embed provider assets only on pages
 * that need them, so themes call `do_action('footer_assets')` once
 * instead of hand-copying conditional markup into footer.php.
 */
final class FooterAssets
{
    public static function render(): void
    {
        if (MediaViewer::isUsed()) {
            $showFilenames = FeaturedImages::config()->option('lightbox_show_filenames', '0') === '1';
            ?>
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/photoswipe@5.4.4/dist/photoswipe.css">
            <?php
            /*
             * The "Show filenames in lightbox" setting reaches
             * media-viewer.js via a data-* attribute on this same
             * <script> tag, not an inline <script> — this project's
             * Content-Security-Policy script-src has no
             * 'unsafe-inline'/nonce allowance (only style-src does, via
             * csp_style_nonce()), so an inline script here would be
             * silently blocked by every browser with no server-side
             * error to catch it. A data-* attribute on an
             * externally-src'd <script> isn't inline script execution,
             * so CSP has no opinion on it.
             */
            ?>
            <script type="module" data-lp-media-viewer data-show-filenames="<?= $showFilenames ? '1' : '0' ?>" src="<?= esc_url(core_asset_url('js/media-viewer.js')) ?>"></script>
            <?php
        }

        if (ScriptEmbeds::isUsed('plyr')) {
            /*
             * Plyr has no ESM build (unlike PhotoSwipe above), so it
             * loads as a plain classic <script> defining window.Plyr —
             * still CSP-safe since only inline script execution is
             * blocked, not an externally-src'd file. media-player.js
             * runs after it and just calls `new Plyr(...)`.
             */
            ?>
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/plyr@3.7.8/dist/plyr.css">
            <script src="https://cdn.jsdelivr.net/npm/plyr@3.7.8/dist/plyr.min.js"></script>
            <script src="<?= esc_url(core_asset_url('js/media-player.js')) ?>"></script>
            <?php
        }

        if (ScriptEmbeds::isUsed('twitter')) {
            ?>
            <script async src="https://platform.twitter.com/widgets.js"></script>
            <?php
        }

        if (ScriptEmbeds::isUsed('bluesky')) {
            ?>
            <script async src="https://embed.bsky.app/static/embed.js" charset="utf-8"></script>
            <?php
        }
    }
}
