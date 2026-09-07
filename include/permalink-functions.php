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
use LumoraPress\Core\Theme\FeaturedImages;
use LumoraPress\Core\Theme\Permalinks;
use LumoraPress\Models\Category;
use LumoraPress\Models\Page;
use LumoraPress\Models\Post;
use LumoraPress\Models\SearchResult;
use LumoraPress\Models\Tag;

/**
 * Post/category/tag permalink helpers — the single choke point every
 * hand-built URL-building call site was migrated to, so a configured
 * permalink_structure/category_base/tag_base option is honored
 * everywhere a link is built.
 */

if (!function_exists('post_permalink')) {
    function post_permalink(Post $post): string
    {
        return preview_theme_link(Permalinks::service()->postUrl($post));
    }
}

if (!function_exists('page_permalink')) {
    /**
     * A Page's public URL, reflecting its parent/child hierarchy — e.g.
     * "/about/team" for a "Team" page under "About". No PermalinkService
     * involvement: pages have no configurable permalink structure, so
     * the ancestor chain alone determines the URL.
     *
     * Absolute (home_url()), not root-relative — this backs the sitemap
     * (absolute <loc> required), Open Graph meta (og:url must be
     * absolute), and emailed comment-notification links (no "current
     * site" for a mail client to resolve against). A relative URL in any
     * of those would be a silent bug.
     */
    function page_permalink(Page $page): string
    {
        $segments = array_map(
            static fn (Page $ancestor): string => $ancestor->slug,
            ActivePages::pages()->ancestors($page->id),
        );
        $segments[] = $page->slug;

        return preview_theme_link(home_url(implode('/', $segments)));
    }
}

if (!function_exists('category_permalink')) {
    function category_permalink(Category $category): string
    {
        return preview_theme_link(Permalinks::service()->categoryUrl($category));
    }
}

if (!function_exists('tag_permalink')) {
    function tag_permalink(Tag $tag): string
    {
        return preview_theme_link(Permalinks::service()->tagUrl($tag));
    }
}

if (!function_exists('has_category_image')) {
    function has_category_image(Category $category): bool
    {
        return $category->imageId !== null && FeaturedImages::media()->find($category->imageId) !== null;
    }
}

if (!function_exists('category_image_url')) {
    /**
     * The public URL for $category's image at $size, falling back to the
     * original image if no thumbnail of that size was generated. No
     * manual-crop support — unlike a post/page featured image, a
     * category image has no placement choice to make cropping worth the
     * added complexity.
     */
    function category_image_url(Category $category, string $size = 'medium'): ?string
    {
        if ($category->imageId === null) {
            return null;
        }

        $media = FeaturedImages::media()->find($category->imageId);

        if ($media === null) {
            return null;
        }

        return FeaturedImages::thumbnails()->url($media, $size) ?? FeaturedImages::media()->url($media);
    }
}

if (!function_exists('privacy_policy_url')) {
    /**
     * The site's configured Privacy Policy Page's URL (Settings >
     * Privacy), or null when none is set or the page no longer
     * exists/isn't publicly visible. A theme decides for itself
     * whether/where to link it — nothing here is auto-inserted.
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
     * The Categories $post belongs to, alphabetical (same ordering
     * PermalinkService::postUrl() relies on for %category%). Empty
     * array for an uncategorized post, never null.
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
     * post_categories(), rendered as a $separator-joined list of links.
     * Outputs nothing for an uncategorized post, so a theme can call
     * this unconditionally.
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
     * signed in or isn't allowed to edit this specific post — mirrors
     * the edit_others_posts/ownership check admin's post list already
     * uses. Themes decide for themselves whether/how to link it.
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
     * edit_post_link()'s Page counterpart — Pages have no separate
     * capability set, reusing 'edit_others_posts'.
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
     * A SearchResult row's public URL. SearchResult is data-only and
     * doesn't carry a post's category/author, so those tokens fall back
     * the same way PermalinkService does for an uncategorized post. A
     * 'page' and 'category' results are handled before the match: their
     * real hierarchical URL needs the actual Page/Category (for
     * page_permalink()'s/category_permalink()'s ancestor chain), falling
     * back to a flat URL only if the row was since deleted.
     */
    function search_result_permalink(SearchResult $result): string
    {
        if ($result->type === 'page') {
            $page = ActivePages::pages()->findBySlug($result->slug);

            // page_permalink() already threads a preview through itself
            // — don't double-append for the common case, only for the
            // site_url() fallback below, which doesn't.
            return $page !== null ? page_permalink($page) : preview_theme_link(site_url($result->slug));
        }

        if ($result->type === 'category') {
            $category = ActiveCategories::categories()->findBySlug($result->slug);

            return $category !== null
                ? category_permalink($category)
                : preview_theme_link(Permalinks::service()->categoryUrlFromSlug($result->slug));
        }

        $permalinks = Permalinks::service();

        return preview_theme_link(match ($result->type) {
            'post' => $permalinks->postUrlForSlugAndDate($result->slug, $result->publishedAt),
            'tag' => $permalinks->tagUrlFromSlug($result->slug),
            'author' => site_url('author/' . $result->slug),
            default => $permalinks->postUrlForSlugAndDate($result->slug, $result->publishedAt),
        });
    }
}
