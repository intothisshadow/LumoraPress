<?php

/**
 * Resolves the configured permalink structure into real post/category/tag URLs, and into the Router pattern that matches them.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use DateTimeImmutable;
use LumoraPress\Core\PressConfig;
use LumoraPress\Models\Category;
use LumoraPress\Models\Post;
use LumoraPress\Models\Tag;

/**
 * The single choke point for post/category/tag URL construction — theme helpers, every
 * controller/service/admin view, and Router registration all go through this class, so a
 * site owner's permalink_structure/category_base/tag_base is honored everywhere at once.
 *
 * postUrl()'s token substitution and postRoutePattern()'s route-pattern compilation are two
 * views of the same %token% -> value mapping, kept together here so the default structure
 * compiles to the exact route pattern this application always registered.
 */
final class PermalinkService
{
    private const DEFAULT_STRUCTURE = '/post/%postname%/';

    private const DEFAULT_CATEGORY_BASE = 'category';

    private const DEFAULT_TAG_BASE = 'tag';

    public function __construct(
        private readonly PressConfig $config,
        private readonly CategoryService $categories,
        private readonly UserService $users,
    ) {
    }

    public function structure(): string
    {
        $value = trim((string) $this->config->option('permalink_structure', self::DEFAULT_STRUCTURE));

        return $value !== '' ? $value : self::DEFAULT_STRUCTURE;
    }

    public function categoryBase(): string
    {
        return $this->normalizeBase((string) $this->config->option('category_base', self::DEFAULT_CATEGORY_BASE), self::DEFAULT_CATEGORY_BASE);
    }

    public function tagBase(): string
    {
        return $this->normalizeBase((string) $this->config->option('tag_base', self::DEFAULT_TAG_BASE), self::DEFAULT_TAG_BASE);
    }

    /**
     * $post's full public URL — resolves %category%/%author% by looking up
     * its first (alphabetically, per CategoryService::categoriesForPost())
     * assigned category and its author, so every token in a custom
     * structure is honored, not just %postname%/%year%/%monthnum%/%day%.
     */
    public function postUrl(Post $post): string
    {
        $category = $this->categories->categoriesForPost($post->id)[0] ?? null;
        $author = $this->users->findById($post->authorId);

        return $this->buildPostUrl(
            $post->slug,
            $post->publishedAt ?? $post->createdAt,
            $category?->slug,
            $author !== null ? $this->users->authorSlug($author) : null,
        );
    }

    /**
     * A degraded-but-workable post URL built from just a slug and
     * (optional) publish date — for callers that only have those two
     * fields on hand (SearchResult rows), not a full Post they could pass
     * to postUrl(). %category%/%author% fall back to $slug the same way
     * postUrl() falls back when a post has no category/a deleted author —
     * see buildPostUrl()'s docblock.
     */
    public function postUrlForSlugAndDate(string $slug, ?DateTimeImmutable $publishedAt): string
    {
        return $this->buildPostUrl($slug, $publishedAt ?? new DateTimeImmutable(), null, null);
    }

    /**
     * $category's full public URL, reflecting its parent/child hierarchy
     * (e.g. "/category/tv-movies/star-trek" for "Star Trek" under
     * "TV & Movies") — mirrors page_permalink()'s ancestor-chain
     * construction. A top-level category's URL is unaffected: a single
     * segment, exactly as before hierarchical URLs existed.
     */
    public function categoryUrl(Category $category): string
    {
        $segments = array_map(
            static fn (Category $ancestor): string => $ancestor->slug,
            $this->categories->ancestors($category->id),
        );
        $segments[] = $category->slug;

        return home_url($this->categoryBase() . '/' . implode('/', $segments));
    }

    /**
     * A flat, degraded category URL built from just a slug, ignoring any
     * parent hierarchy — only correct for a top-level category. Used
     * exclusively as search_result_permalink()'s last-resort fallback,
     * for the rare case a category matching a stale search-index slug can
     * no longer be looked up by object. Prefer categoryUrl() everywhere
     * else — it always resolves the real nested path.
     */
    public function categoryUrlFromSlug(string $slug): string
    {
        return home_url($this->categoryBase() . '/' . $slug);
    }

    public function tagUrl(Tag $tag): string
    {
        return $this->tagUrlFromSlug($tag->slug);
    }

    public function tagUrlFromSlug(string $slug): string
    {
        return home_url($this->tagBase() . '/' . $slug);
    }

    /**
     * The literal prefix of the post URL up to (not including) %postname%
     * itself, with every other token resolved to a real value — the base
     * admin/assets/js/url-preview.js appends a client-side slugify() of
     * the title/slug field to, live, as the admin types. Only ever used
     * for that display-only preview; the server-rendered "Permalink:" box
     * for an already-published post uses the fully accurate postUrl()
     * instead.
     */
    public function postUrlPreviewBase(?Post $post = null): string
    {
        $structure = $this->structure();
        $postnamePos = strpos($structure, '%postname%');
        $prefix = $postnamePos !== false ? substr($structure, 0, $postnamePos) : rtrim($structure, '/') . '/';

        $date = $post !== null ? ($post->publishedAt ?? $post->createdAt) : new DateTimeImmutable();
        $categorySlug = null;
        $authorSlug = null;

        if ($post !== null) {
            $categorySlug = ($this->categories->categoriesForPost($post->id)[0] ?? null)?->slug;
            $author = $this->users->findById($post->authorId);
            $authorSlug = $author !== null ? $this->users->authorSlug($author) : null;
        }

        $prefix = strtr($prefix, [
            '%year%' => $date->format('Y'),
            '%monthnum%' => $date->format('m'),
            '%day%' => $date->format('d'),
            '%category%' => $categorySlug ?? 'category',
            '%author%' => $authorSlug ?? 'author',
        ]);

        return home_url(ltrim($prefix, '/'));
    }

    /**
     * The Router pattern matching the configured post structure — every %token% becomes a
     * {name} placeholder Router::match() already knows how to capture.
     *
     * %year%/%monthnum%/%day% compile to a raw digit-only regex group rather than Router's
     * normal {name} -> `[^/]+` placeholder, since the hierarchical Page route ("/{path*}")
     * is registered after this one: a bare {year} would swallow a same-depth Page's first
     * segment as a "year" and 404 instead of falling through to the Page route.
     */
    public function postRoutePattern(): string
    {
        $pattern = strtr($this->structure(), [
            '%postname%' => '{slug}',
            '%year%' => '(?P<year>\d\d\d\d)',
            '%monthnum%' => '(?P<monthnum>\d\d?)',
            '%day%' => '(?P<day>\d\d?)',
            '%category%' => '{category}',
            '%author%' => '{author}',
        ]);

        return '/' . trim($pattern, '/');
    }

    /**
     * A greedy "{path*}" placeholder rather than a single-segment
     * "{slug}", so a nested category's full ancestor-chain URL routes —
     * SiteController::category() resolves $params['path'] segment by
     * segment via CategoryService::findByPath(), the same shape
     * PageService::findByPath() already uses for the hierarchical Page
     * route. Because this pattern is greedy, bootstrap.php must register
     * categoryFeedFormatRoutePattern()/categoryFeedRoutePattern() (both
     * more specific — they require a trailing "/feed" or "/feed/{format}"
     * segment) before this one, or a feed URL would incorrectly match
     * here first with "feed" (or "feed/atom") swallowed into $path.
     */
    public function categoryRoutePattern(): string
    {
        return '/' . $this->categoryBase() . '/{path*}';
    }

    public function tagRoutePattern(): string
    {
        return '/' . $this->tagBase() . '/{slug}';
    }

    /**
     * '/tag/{slug}/feed' and '/tag/{slug}/feed/{format}' — the tag-scoped
     * counterparts of SiteController::feed()'s '/feed' and '/feed/{format}'.
     * Registered before the bare tagRoutePattern() for the same ordering
     * reason categoryFeedRoutePattern() is (see its docblock), even though
     * {slug} here only matches a single segment so the two can't actually
     * collide.
     */
    public function tagFeedRoutePattern(): string
    {
        return $this->tagRoutePattern() . '/feed';
    }

    public function tagFeedFormatRoutePattern(): string
    {
        return $this->tagRoutePattern() . '/feed/{format}';
    }

    public function tagFeedUrl(Tag $tag, string $format = 'rss'): string
    {
        return $this->tagUrl($tag) . '/feed' . self::feedFormatSuffix($format);
    }

    /**
     * '/category/{path*}/feed' and '/category/{path*}/feed/{format}' — the
     * category-scoped counterparts of SiteController::feed()'s '/feed' and
     * '/feed/{format}'. See categoryRoutePattern()'s docblock for why
     * bootstrap.php must register these two before the bare
     * categoryRoutePattern() itself.
     */
    public function categoryFeedRoutePattern(): string
    {
        return $this->categoryRoutePattern() . '/feed';
    }

    public function categoryFeedFormatRoutePattern(): string
    {
        return $this->categoryRoutePattern() . '/feed/{format}';
    }

    public function categoryFeedUrl(Category $category, string $format = 'rss'): string
    {
        return $this->categoryUrl($category) . '/feed' . self::feedFormatSuffix($format);
    }

    /**
     * The URL suffix for a feed format — '' for 'rss' (the bare "/feed"
     * URL), '/atom' or '/json' otherwise. Shared by every *FeedUrl()
     * method here so a feed's self-link always matches the format that
     * was actually requested, not a hardcoded assumption.
     */
    public static function feedFormatSuffix(string $format): string
    {
        return match ($format) {
            'atom' => '/atom',
            'json' => '/json',
            default => '',
        };
    }

    /**
     * A post with no assigned category, or whose author account was since
     * deleted, still needs a valid, uniquely-resolvable URL under a
     * custom structure that includes %category%/%author% — falling back
     * to the post's own (globally unique) slug for that segment, rather
     * than dropping the segment, keeps the URL's segment count matching
     * postRoutePattern() exactly, so the generated link always actually
     * routes.
     */
    private function buildPostUrl(string $slug, DateTimeImmutable $date, ?string $categorySlug, ?string $authorSlug): string
    {
        $replacements = [
            '%postname%' => $slug,
            '%year%' => $date->format('Y'),
            '%monthnum%' => $date->format('m'),
            '%day%' => $date->format('d'),
            '%category%' => $categorySlug ?? $slug,
            '%author%' => $authorSlug ?? $slug,
        ];

        $path = trim(strtr($this->structure(), $replacements), '/');

        return home_url($path);
    }

    private function normalizeBase(string $value, string $default): string
    {
        $value = trim($value, " \t\n\r\0\x0B/");

        return $value !== '' ? $value : $default;
    }
}
