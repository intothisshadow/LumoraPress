<?php

/**
 * Procedural template helpers available inside theme template files (get_header(), get_footer(), get_sidebar(), etc.).
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

use LumoraPress\Core\Content\ActiveContentRenderer;
use LumoraPress\Core\Theme\ActiveTheme;
use LumoraPress\Core\Theme\MediaViewer;
use LumoraPress\Models\ContentFormat;

if (!function_exists('get_header')) {
    /**
     * @param array<string, mixed> $vars Passed through to header.php — e.g.
     *     ['post' => $post] so it can render per-item Open Graph/Twitter
     *     Card tags. Optional: every other caller keeps working
     *     unchanged, same "extra vars, default empty" shape as
     *     comments_template().
     */
    function get_header(array $vars = []): void
    {
        do_action('get_header');
        ActiveTheme::instance()->renderPartial('header', $vars);
    }
}

if (!function_exists('get_footer')) {
    function get_footer(): void
    {
        do_action('get_footer');
        ActiveTheme::instance()->renderPartial('footer');
    }
}

if (!function_exists('get_sidebar')) {
    function get_sidebar(): void
    {
        do_action('get_sidebar');
        ActiveTheme::instance()->renderPartial('sidebar');
    }
}

if (!function_exists('theme_url')) {
    function theme_url(string $path = ''): string
    {
        return ActiveTheme::instance()->themeUrl($path);
    }
}

if (!function_exists('render_content')) {
    /**
     * Renders a post/page's raw stored content to safe, final HTML —
     * echoed raw by callers since it's already sanitized, unlike every
     * other template value.
     *
     * Usage: <?= render_content($post->content, $post->contentFormat) ?>
     *
     * When ContentRenderer's lightbox pass touched at least one image,
     * this wraps the result in `.lp-gallery` and flags MediaViewer as
     * used, gating PhotoSwipe assets the same way
     * the_post_thumbnail_lightbox() does for featured images. Kept here
     * rather than in ContentRenderer since render() is also used for
     * excerpts, where marking a lightbox would be meaningless.
     *
     * Checks for data-pswp-lightbox OR data-pswp-width: Markdown-authored
     * images never get a known width (no attribute syntax), so
     * data-pswp-lightbox is the one marker always present when the pass
     * touched an image.
     */
    function render_content(string $content, ContentFormat $format): string
    {
        $html = ActiveContentRenderer::instance()->render($content, $format);

        if (!str_contains($html, 'data-pswp-lightbox') && !str_contains($html, 'data-pswp-width')) {
            return $html;
        }

        MediaViewer::markUsed();

        return '<div class="lp-gallery">' . $html . '</div>';
    }
}

if (!function_exists('content_plain_text')) {
    /**
     * Plain-text rendering of a post/page's content for excerpt/OG
     * description fallbacks — see ContentRenderer::toPlainText() for why
     * this can't just be strip_tags() when $format is Markdown. Returns
     * unescaped plain text — callers must esc_html() it.
     */
    function content_plain_text(string $content, ContentFormat $format): string
    {
        return ActiveContentRenderer::instance()->toPlainText($content, $format);
    }
}

if (!function_exists('content_has_more_tag')) {
    /**
     * See ContentRenderer::hasMoreTag()'s docblock.
     */
    function content_has_more_tag(string $content): bool
    {
        return ActiveContentRenderer::instance()->hasMoreTag($content);
    }
}

if (!function_exists('content_split_at_more_tag')) {
    /**
     * See ContentRenderer::splitAtMoreTag()'s docblock.
     *
     * @return array{0: string, 1: ?string}
     */
    function content_split_at_more_tag(string $content): array
    {
        return ActiveContentRenderer::instance()->splitAtMoreTag($content);
    }
}

if (!function_exists('comments_template')) {
    /**
     * Renders the active theme's comments.php partial, if it has one —
     * silently does nothing otherwise, same as get_sidebar().
     *
     * @param array<string, mixed> $vars
     */
    function comments_template(array $vars = []): void
    {
        do_action('comments_template');
        ActiveTheme::instance()->renderPartial('comments', $vars);
    }
}
