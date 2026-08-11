<?php

/**
 * Post/category/tag permalink helpers (LP-078) for theme templates and application code, backed by PermalinkService via the Permalinks static bridge.
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

use LumoraPress\Core\Theme\Permalinks;
use LumoraPress\Models\Category;
use LumoraPress\Models\Post;
use LumoraPress\Models\SearchResult;
use LumoraPress\Models\Tag;

/**
 * Post/category/tag permalink helpers (LP-078) — the single choke point
 * every hand-built 'post/' . $post->slug / 'category/' . $category->slug /
 * 'tag/' . $tag->slug call site was migrated to, so a configured
 * permalink_structure/category_base/tag_base option is honored everywhere
 * a link is built, not just in newly-written code. Mirrors
 * include/author-functions.php's the_author_link()/author_url() shape.
 */

if (!function_exists('post_permalink')) {
    function post_permalink(Post $post): string
    {
        return Permalinks::service()->postUrl($post);
    }
}

if (!function_exists('category_permalink')) {
    function category_permalink(Category $category): string
    {
        return Permalinks::service()->categoryUrl($category);
    }
}

if (!function_exists('tag_permalink')) {
    function tag_permalink(Tag $tag): string
    {
        return Permalinks::service()->tagUrl($tag);
    }
}

if (!function_exists('search_result_permalink')) {
    /**
     * A SearchResult row's public URL. SearchResult is deliberately
     * data-only (see its own docblock) and doesn't carry a post's
     * category/author, so a 'post' result's %category%/%author% tokens
     * (if the configured structure uses them) fall back the same way
     * PermalinkService::postUrl() falls back for an uncategorized post —
     * see PermalinkService::postUrlForSlugAndDate()'s docblock.
     */
    function search_result_permalink(SearchResult $result): string
    {
        $permalinks = Permalinks::service();

        return match ($result->type) {
            'post' => $permalinks->postUrlForSlugAndDate($result->slug, $result->publishedAt),
            'page' => site_url('page/' . $result->slug),
            'category' => $permalinks->categoryUrlFromSlug($result->slug),
            'tag' => $permalinks->tagUrlFromSlug($result->slug),
            'author' => site_url('author/' . $result->slug),
            default => $permalinks->postUrlForSlugAndDate($result->slug, $result->publishedAt),
        };
    }
}
