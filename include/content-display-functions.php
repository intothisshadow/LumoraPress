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
 * LP-079 — the classic WordPress content model (full post via
 * the_content(), post preview via the_excerpt(), author-selected cutoff
 * via the More tag) reimplemented as thin orchestration over pieces that
 * already exist: render_content()/content_plain_text() (include/theme.php,
 * backed by ContentRenderer), content_split_at_more_tag() (also
 * include/theme.php), make_excerpt() and theme_option() (include/
 * helpers.php). No new bridge/service is needed here — every dependency
 * is already globally available by the time a theme template runs.
 */

if (!function_exists('get_the_content')) {
    /**
     * $post's full rendered content, with any More tag marker stripped
     * (never left visible as raw markup — LP-079's own requirement) but
     * every character on both sides of it kept. Always the complete
     * post, regardless of the "Post Display" Theme Option — that option
     * only ever affects front-page/archive listings, never a single-post
     * view (see content/themes/default/single.php, which always calls
     * this rather than get_the_excerpt()).
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
     * Echoes get_the_content($post) — already-safe HTML, same
     * "pre-sanitized, don't esc_html() it" convention render_content()
     * itself documents.
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
     * preserving formatting/images/etc. — unlike get_the_excerpt(),
     * which strips everything down to plain text. Returns null when
     * $post has no More tag at all, so a caller can tell "nothing to
     * cut" from "cut here, but the result happens to render empty."
     *
     * A post's own More tag always takes priority over the "Post
     * Display" Theme Option on a listing page — the same way classic
     * WordPress's the_content() always respects <!--more--> outside a
     * singular view regardless of the "For each article in a feed,
     * show" setting. An author who deliberately placed a cutoff gets
     * that cutoff honored even when the site is otherwise configured to
     * show full posts in listings; see content/themes/default/
     * index.php/archive.php, which check this before checking
     * theme_option('post_display_mode').
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
     * $post's preview text, in priority order (LP-079's "Manual
     * Excerpts"/"More tag" sections):
     *
     *   1. The manually entered Excerpt field, if not blank — always
     *      wins outright, even over a More tag the same post also has
     *      (classic WordPress's own precedence for wp_trim_excerpt()).
     *   2. The content up to the author's own More tag, if present —
     *      taken as-is, never further truncated by $wordLimit; the
     *      author already chose the cutoff, second-guessing it with a
     *      word limit would defeat the point of a manual cutoff at all.
     *   3. An automatically generated excerpt (make_excerpt()) from the
     *      full rendered content, trimmed to $wordLimit words — the
     *      Theme Options "Automatic Excerpt Length" value
     *      (theme_option('excerpt_length')) when $wordLimit is omitted.
     *
     * Returns unescaped plain text — the same "caller must esc_html()
     * it" convention make_excerpt()/content_plain_text() already follow.
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
     * Echoes esc_html(get_the_excerpt($post)) — plain text output, the
     * same convention every template that used to print $post->excerpt
     * directly already followed.
     */
    function the_excerpt(Post $post): void
    {
        echo esc_html(get_the_excerpt($post));
    }
}

if (!function_exists('get_page_breadcrumbs')) {
    /**
     * $page's breadcrumb trail as {title, url} pairs, root-first, not
     * including $page itself (a template appends the current page's own
     * title after these, exactly as it already has $page->title
     * available). $ancestors is the root-first Page[] chain a caller
     * already computed via PageService::ancestors() — SiteController::page()
     * passes it in as the 'page_ancestors' template var, since theme
     * templates only ever receive curated $vars, never a raw service
     * (LP-009's Hierarchy UI). Each link is that ancestor's own real
     * hierarchical URL (LP-084's page_permalink()), not a flat one.
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
     * Renders $ancestors (see get_page_breadcrumbs()) as an escaped
     * <nav>/<ol> breadcrumb trail, ending in $page's own (unlinked)
     * title. Outputs nothing for a top-level page ($ancestors === []) —
     * a single-entry trail with nothing to navigate to isn't useful.
     * Styling belongs entirely to the active theme's stylesheet
     * (.lp-breadcrumbs and friends — see content/themes/default/style.css),
     * per this project's Public-Facing CSS Rule.
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
