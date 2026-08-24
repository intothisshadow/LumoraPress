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

use LumoraPress\Core\ActiveConfig;
use LumoraPress\Core\Theme\ActiveAuth;
use LumoraPress\Core\Theme\ActiveCategories;
use LumoraPress\Core\Theme\ActivePages;
use LumoraPress\Core\Theme\Permalinks;
use LumoraPress\Models\Category;
use LumoraPress\Models\Page;
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

if (!function_exists('page_permalink')) {
    /**
     * A Page's public URL — always flat ('page/{slug}'), regardless of
     * nesting depth (LP-009's Hierarchy UI adds parent/child structure
     * to the admin tree view, but hierarchical URLs are a separate,
     * not-yet-built ticket — see DECISIONS.md/TODO.md). No
     * PermalinkService involvement: unlike posts/categories/tags, pages
     * have no configurable permalink structure to honor (see LP-078's
     * "Explicitly Out of Scope" note in TODO.md).
     */
    function page_permalink(Page $page): string
    {
        return site_url('page/' . $page->slug);
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

if (!function_exists('privacy_policy_url')) {
    /**
     * The site's configured Privacy Policy Page's URL (Settings >
     * Privacy), or null when none is set or the configured page no
     * longer exists/isn't publicly visible — a theme decides for itself
     * whether/where to link it (e.g. a footer credit line, a comment
     * form notice), matching page_permalink()'s "themes own their own
     * markup" shape rather than this being auto-inserted anywhere.
     */
    function privacy_policy_url(): ?string
    {
        $pageId = (int) ActiveConfig::instance()->option('privacy_policy_page_id', '0');

        if ($pageId <= 0) {
            return null;
        }

        $page = ActivePages::pages()->findById($pageId);

        return $page !== null && $page->isPubliclyVisible() ? page_permalink($page) : null;
    }
}

if (!function_exists('post_categories')) {
    /**
     * The Categories $post itself belongs to (CategoryService::
     * categoriesForPost() — alphabetical, same ordering
     * PermalinkService::postUrl() already relies on for its own
     * %category% token). Empty array for an uncategorized post, never
     * null, so a theme can loop it directly without an extra null check.
     *
     * @return array<int, Category>
     */
    function post_categories(Post $post): array
    {
        return ActiveCategories::categories()->categoriesForPost($post->id);
    }
}

if (!function_exists('the_post_categories')) {
    /**
     * post_categories(), rendered as a $separator-joined list of links
     * (mirrors the_author_link()'s "echo, don't return markup" shape) —
     * outputs nothing at all for an uncategorized post rather than an
     * empty line, so a theme can call this unconditionally.
     */
    function the_post_categories(Post $post, string $separator = ', '): void
    {
        $links = array_map(
            static fn (Category $category): string => '<a href="' . esc_url(category_permalink($category)) . '">' . esc_html($category->name) . '</a>',
            post_categories($post),
        );

        if ($links === []) {
            return;
        }

        echo implode(esc_html($separator), $links);
    }
}

if (!function_exists('edit_post_link')) {
    /**
     * The admin edit-screen URL for $post, or null when nobody is
     * signed in or the signed-in user isn't allowed to edit this
     * specific post — mirrors admin/views/posts/all-posts.php's own
     * $canEditPost closure exactly (edit_others_posts, or ownership of
     * this particular post) so a theme's "Edit this post" link only
     * ever shows to someone who could actually use it. Themes decide
     * for themselves whether/how to link it, same as page_permalink()/
     * privacy_policy_url() — nothing here is auto-inserted anywhere.
     */
    function edit_post_link(Post $post): ?string
    {
        $user = ActiveAuth::auth()->user();

        if ($user === null) {
            return null;
        }

        if (!$user->can('edit_others_posts') && $post->authorId !== $user->id) {
            return null;
        }

        return admin_url('posts/new') . '?id=' . $post->id;
    }
}

if (!function_exists('edit_page_link')) {
    /**
     * edit_post_link()'s own Page counterpart — Pages have no separate
     * capability set of their own (admin/views/pages/all-pages.php's
     * $canEditOthersPages reads the same 'edit_others_posts' capability
     * post editing already uses), so the gating logic mirrors it exactly.
     */
    function edit_page_link(Page $page): ?string
    {
        $user = ActiveAuth::auth()->user();

        if ($user === null) {
            return null;
        }

        if (!$user->can('edit_others_posts') && $page->authorId !== $user->id) {
            return null;
        }

        return admin_url('pages/new') . '?id=' . $page->id;
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
