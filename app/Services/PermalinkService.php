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
 * LP-078: the single choke point for post/category/tag URL construction.
 * `include/permalink-functions.php`'s post_permalink()/category_permalink()/
 * tag_permalink() theme helpers, every controller/service/admin view that
 * used to hand-build 'post/' . $post->slug, and bootstrap.php's own Router
 * registration all go through this class, so a site owner's chosen
 * permalink_structure/category_base/tag_base option is honored everywhere
 * at once rather than in some places and not others.
 *
 * postUrl()'s token substitution and postRoutePattern()'s route-pattern
 * compilation are two views of the same %token% -> value mapping — one
 * substitutes a real value per post, the other substitutes a Router
 * {name} placeholder once at bootstrap time. Keeping both here (rather
 * than splitting pattern compilation into Router itself) is what lets the
 * default structure ('/post/%postname%/') compile to the exact
 * '/post/{slug}' pattern this application always registered, so an
 * unconfigured site's URLs stay byte-for-byte unchanged.
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

    public function categoryUrl(Category $category): string
    {
        return $this->categoryUrlFromSlug($category->slug);
    }

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
     * The Router pattern matching the configured post structure — every
     * %token% becomes a {name} placeholder Router::match() already knows
     * how to capture (see Router's own docblock), so no Router change was
     * needed to support date/category/author segments. The default
     * structure compiles to exactly '/post/{slug}', the same pattern this
     * application registered before permalink structures existed.
     */
    public function postRoutePattern(): string
    {
        $pattern = strtr($this->structure(), [
            '%postname%' => '{slug}',
            '%year%' => '{year}',
            '%monthnum%' => '{monthnum}',
            '%day%' => '{day}',
            '%category%' => '{category}',
            '%author%' => '{author}',
        ]);

        return '/' . trim($pattern, '/');
    }

    public function categoryRoutePattern(): string
    {
        return '/' . $this->categoryBase() . '/{slug}';
    }

    public function tagRoutePattern(): string
    {
        return '/' . $this->tagBase() . '/{slug}';
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
