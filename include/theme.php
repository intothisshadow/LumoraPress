<?php

declare(strict_types=1);

use LumoraPress\Core\Content\ActiveContentRenderer;
use LumoraPress\Core\Theme\ActiveTheme;
use LumoraPress\Models\ContentFormat;

/**
 * Procedural template helpers available inside theme template files.
 */

if (!function_exists('get_header')) {
    /**
     * @param array<string, mixed> $vars Passed through to header.php — e.g.
     *     ['post' => $post] so it can render per-item Open Graph/Twitter
     *     Card tags (LP-040). Optional: every other caller keeps working
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
     * Renders a post/page's raw stored content to safe, final HTML
     * (LP-015/LP-016) — branches on $format via ContentRenderer, echoed
     * raw by callers (it is already sanitized, unlike every other
     * template value, which is why this returns pre-escaped HTML instead
     * of following the project's usual esc_html()-at-output convention).
     *
     * Usage: <?= render_content($post->content, $post->contentFormat) ?>
     */
    function render_content(string $content, ContentFormat $format): string
    {
        return ActiveContentRenderer::instance()->render($content, $format);
    }
}

if (!function_exists('content_plain_text')) {
    /**
     * Plain-text rendering of a post/page's content for excerpt/OG
     * description fallbacks — see ContentRenderer::toPlainText()'s
     * docblock for why this can't just be strip_tags($content) directly
     * when $format is Markdown. Returns unescaped plain text, same
     * "callers must esc_html() it" convention as make_excerpt().
     */
    function content_plain_text(string $content, ContentFormat $format): string
    {
        return ActiveContentRenderer::instance()->toPlainText($content, $format);
    }
}

if (!function_exists('comments_template')) {
    /**
     * Renders the active theme's comments.php partial, if it has one —
     * silently does nothing otherwise, the same graceful-fallback
     * behavior as get_sidebar() for themes with no sidebar.php.
     *
     * @param array<string, mixed> $vars
     */
    function comments_template(array $vars = []): void
    {
        do_action('comments_template');
        ActiveTheme::instance()->renderPartial('comments', $vars);
    }
}
