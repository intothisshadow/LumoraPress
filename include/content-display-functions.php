<?php

/**
 * WordPress-style the_content()/get_the_content()/the_excerpt()/get_the_excerpt() theme helpers (LP-079).
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

use LumoraPress\Models\Page;
use LumoraPress\Models\Post;

/**
 * The classic WordPress content model (the_content()/the_excerpt()/the
 * More tag), reimplemented as thin orchestration over pieces that
 * already exist in include/theme.php and include/helpers.php.
 */

if (!function_exists('get_the_content')) {
    /**
     * $post's full rendered content, with any More tag marker stripped.
     * Always the complete post — "Post Display" only affects listings.
     */
    function get_the_content(Post $post): string
    {
        [$before, $after] = content_split_at_more_tag($post->content);
        $full = $after !== null ? $before . $after : $post->content;

        return render_content($full, $post->contentFormat);
    }
}

if (!function_exists('the_content')) {
    /**
     * Echoes get_the_content($post) — already-safe HTML, not to be
     * esc_html()'d.
     */
    function the_content(Post $post): void
    {
        echo get_the_content($post);
    }
}

if (!function_exists('post_has_more_tag')) {
    function post_has_more_tag(Post $post): bool
    {
        return content_has_more_tag($post->content);
    }
}

if (!function_exists('get_the_content_up_to_more_tag')) {
    /**
     * $post's rendered content up to (not including) its More tag,
     * preserving formatting — unlike get_the_excerpt(), which strips to
     * plain text. Returns null when $post has no More tag, so a caller
     * can distinguish "nothing to cut" from an empty result.
     *
     * A post's own More tag always takes priority over the "Post
     * Display" Theme Option on listing pages, matching classic
     * WordPress's the_content() behavior.
     */
    function get_the_content_up_to_more_tag(Post $post): ?string
    {
        [$before, $after] = content_split_at_more_tag($post->content);

        if ($after === null) {
            return null;
        }

        return render_content($before, $post->contentFormat);
    }
}

if (!function_exists('get_the_excerpt')) {
    /**
     * $post's preview text, in priority order: (1) the manually entered
     * Excerpt field, if not blank; (2) content up to the author's own
     * More tag, taken as-is, never truncated further; (3) an
     * auto-generated excerpt trimmed to $wordLimit words (defaults to
     * the "Automatic Excerpt Length" Theme Option).
     *
     * Returns unescaped plain text — callers must esc_html() it.
     */
    function get_the_excerpt(Post $post, ?int $wordLimit = null): string
    {
        if ($post->excerpt !== '') {
            return $post->excerpt;
        }

        [$before, $after] = content_split_at_more_tag($post->content);

        if ($after !== null) {
            return trim(content_plain_text($before, $post->contentFormat));
        }

        $length = $wordLimit ?? max(1, (int) theme_option('excerpt_length'));

        return make_excerpt(content_plain_text($post->content, $post->contentFormat), $length);
    }
}

if (!function_exists('the_excerpt')) {
    /**
     * Echoes esc_html(get_the_excerpt($post)) as plain text.
     */
    function the_excerpt(Post $post): void
    {
        echo esc_html(get_the_excerpt($post));
    }
}

if (!function_exists('get_page_breadcrumbs')) {
    /**
     * $page's breadcrumb trail as {title, url} pairs, root-first, not
     * including $page itself. $ancestors is the root-first chain
     * SiteController::page() already computed and passed in as
     * 'page_ancestors'.
     *
     * @param array<int, Page> $ancestors
     * @return array<int, array{title: string, url: string}>
     */
    function get_page_breadcrumbs(array $ancestors): array
    {
        return array_map(
            static fn (Page $ancestor): array => ['title' => $ancestor->title, 'url' => page_permalink($ancestor)],
            $ancestors,
        );
    }
}

if (!function_exists('the_page_breadcrumbs')) {
    /**
     * Renders $ancestors as an escaped <nav>/<ol> breadcrumb trail,
     * ending in $page's own (unlinked) title. Outputs nothing for a
     * top-level page. Styling belongs to the theme's stylesheet
     * (.lp-breadcrumbs), per the Public-Facing CSS Rule.
     *
     * @param array<int, Page> $ancestors
     */
    function the_page_breadcrumbs(Page $page, array $ancestors): void
    {
        if ($ancestors === []) {
            return;
        }

        echo '<nav class="lp-breadcrumbs" aria-label="Breadcrumb"><ol class="lp-breadcrumbs__list">';

        foreach (get_page_breadcrumbs($ancestors) as $crumb) {
            echo '<li class="lp-breadcrumbs__item">'
                . '<a class="lp-breadcrumbs__link" href="' . esc_url($crumb['url']) . '">' . esc_html($crumb['title']) . '</a>'
                . '<span class="lp-breadcrumbs__separator" aria-hidden="true">/</span>'
                . '</li>';
        }

        echo '<li class="lp-breadcrumbs__item lp-breadcrumbs__item--current" aria-current="page">' . esc_html($page->title) . '</li>';
        echo '</ol></nav>';
    }
}
