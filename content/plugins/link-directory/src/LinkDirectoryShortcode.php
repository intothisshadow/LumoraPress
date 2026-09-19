<?php

/**
 * Renders the `[lumora_link_directory]` shortcode using this plugin's own tables.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.10.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LinkDirectory;

use LumoraPress\Core\Content\HtmlSanitizer;
use LumoraPress\Core\Content\MarkdownParser;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Services\ContentRenderer;
use LumoraPress\Services\MediaService;

/**
 * Registered on the content_html filter (see link-directory.php); needs
 * real database-backed services a plugin's load-time code can't reach yet,
 * so it opens its own Database connection rather than waiting for $kernel —
 * the same reasoning Downloads' own DownloadsShortcode documents.
 */
final class LinkDirectoryShortcode
{
    private const PATTERN = '/\[lumora_link_directory([^\]]*)\]/i';

    /**
     * A GET param, not a shortcode attribute — clicking a category in the
     * "every category" view (renderCategoryList()) re-requests the current
     * page with this appended, so one bare `[lumora_link_directory]`
     * placement can browse into a category without a separate
     * page-per-category shortcode (LPP-027; see this plugin's own README
     * for the page-per-shortcode approach this replaces for the common
     * case). An explicit `category`/`category_id` attribute always wins
     * over this, so an admin who deliberately pinned a shortcode to one
     * category is never redirected by a stray query string.
     */
    private const CATEGORY_QUERY_PARAM = 'lp_link_category';

    /**
     * $injectedLinks/$injectedCategories are given together or not at all
     * (tests use SQLite fixtures; the plugin's bootstrap passes none, so
     * services() lazily opens a real connection). $injectedContent and
     * $injectedMedia are each independent.
     */
    public function __construct(
        private readonly ?LinkService $injectedLinks = null,
        private readonly ?LinkDirectoryCategoryService $injectedCategories = null,
        private readonly ?ContentRenderer $injectedContent = null,
        private readonly ?MediaService $injectedMedia = null,
    ) {
    }

    public function renderShortcodes(string $html): string
    {
        if (!str_contains($html, '[lumora_link_directory')) {
            return $html;
        }

        $result = preg_replace_callback(
            self::PATTERN,
            fn (array $matches): string => $this->renderOne($this->parseAttributes($matches[1])),
            $html,
        );

        return $result ?? $html;
    }

    /**
     * Three variants, checked in order: `link_id` (a single link by id),
     * `category`/`category_id` (that category's own links, the "show
     * listings in a single category" checklist item), or neither (every
     * category with its link count, the "show all categories" item).
     *
     * @param array<string, string> $attributes
     */
    private function renderOne(array $attributes): string
    {
        [$links, $categories] = $this->services();

        if (($attributes['link_id'] ?? '') !== '') {
            $link = $links->findById((int) $attributes['link_id']);

            if ($link === null || $link->trashedAt !== null) {
                return '';
            }

            return $this->renderLinks([$link]);
        }

        if (($attributes['category_id'] ?? '') !== '' || ($attributes['category'] ?? '') !== '') {
            $category = $this->resolveCategory($categories, $attributes);

            if ($category === null) {
                return '';
            }

            $items = $links->listByCategory($category->id);

            return $items === [] ? '' : $this->renderLinks($items);
        }

        $requestedCategoryId = $this->requestedCategoryId();

        if ($requestedCategoryId !== null) {
            $category = $categories->findById($requestedCategoryId);

            if ($category !== null && $category->trashedAt === null) {
                return $this->renderCategoryDetail($categories, $links, $category);
            }
        }

        return $this->renderCategoryList($categories);
    }

    /**
     * The category-drilldown view a clicked category link (renderCategoryList())
     * or a manually-shared URL lands on: a "back to all categories" link,
     * any direct sub-categories (themselves clickable, for further
     * drilldown), and this category's own direct links. Unlike the
     * explicit-attribute path above, an empty category still renders
     * something here — the admin clicked into it and needs to see *why*
     * it's empty, not have the whole shortcode vanish.
     */
    private function renderCategoryDetail(LinkDirectoryCategoryService $categories, LinkService $links, LinkDirectoryCategory $category): string
    {
        $childCategories = $categories->directChildren($category->id);
        $items = $links->listByCategory($category->id);

        $html = '<div class="lp-link-directory-category">';
        $html .= '<p class="lp-link-directory-category__back"><a href="' . esc_url($this->categoriesBaseUrl()) . '">&larr; All categories</a></p>';
        $html .= '<h3 class="lp-link-directory-category__title">' . esc_html($category->name) . '</h3>';

        if ($childCategories !== []) {
            $html .= '<ul class="lp-link-directory-categories lp-link-directory-categories--nested">';

            foreach ($childCategories as $child) {
                $html .= '<li class="lp-link-directory-categories__item">'
                    . '<a href="' . esc_url($this->categoryLinkUrl($child->id)) . '">' . esc_html($child->name) . '</a>'
                    . ' (' . $categories->linkCount($child->id) . ')'
                    . '</li>';
            }

            $html .= '</ul>';
        }

        $html .= $items === []
            ? '<p class="lp-link-directory-category__empty">No links in this category yet.</p>'
            : $this->renderLinks($items);

        $html .= '</div>';

        return $html;
    }

    /**
     * The current request's own path/query with CATEGORY_QUERY_PARAM
     * pointed at $categoryId — used for both the "browse categories" links
     * and, with $categoryId omitted, the drilldown view's own "back" link.
     * Mirrors render_pagination()'s (include/helpers.php) identical
     * path+query-preserving approach for its own "paged" param.
     */
    private function categoryLinkUrl(?int $categoryId = null): string
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($requestUri, PHP_URL_PATH);
        parse_str((string) parse_url($requestUri, PHP_URL_QUERY), $query);

        if ($categoryId === null) {
            unset($query[self::CATEGORY_QUERY_PARAM]);
        } else {
            $query[self::CATEGORY_QUERY_PARAM] = $categoryId;
        }

        return $path . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    private function categoriesBaseUrl(): string
    {
        return $this->categoryLinkUrl(null);
    }

    /**
     * A positive category id from the query string, or null when absent/invalid.
     */
    private function requestedCategoryId(): ?int
    {
        $raw = $_GET[self::CATEGORY_QUERY_PARAM] ?? null;

        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }

    private function content(): ContentRenderer
    {
        return $this->injectedContent ?? new ContentRenderer(new MarkdownParser(), new HtmlSanitizer(), new HookManager());
    }

    /**
     * `category_id` (an exact LinkDirectoryCategory id) wins if given;
     * otherwise `category` is matched case-insensitively against the
     * category's own name — LinkDirectoryCategory has no slug column.
     * Neither attribute given, or no matching category, both resolve to null.
     *
     * @param array<string, string> $attributes
     */
    private function resolveCategory(LinkDirectoryCategoryService $categories, array $attributes): ?LinkDirectoryCategory
    {
        $categoryId = (int) ($attributes['category_id'] ?? 0);

        if ($categoryId > 0) {
            return $categories->findById($categoryId);
        }

        $categoryName = trim($attributes['category'] ?? '');

        if ($categoryName === '') {
            return null;
        }

        foreach ($categories->listAll() as $category) {
            if (strcasecmp($category->name, $categoryName) === 0) {
                return $category;
            }
        }

        return null;
    }

    /**
     * The "show listings in a single category" (or single-link) rendering:
     * one card per link with its title, thumbnail, description, and URL.
     *
     * @param array<int, Link> $items
     */
    private function renderLinks(array $items): string
    {
        $content = $this->content();
        $media = $this->media();

        $html = '<div class="lp-link-directory-list">';

        foreach ($items as $item) {
            $html .= '<article class="lp-link-directory-item">';
            $html .= '<h3 class="lp-link-directory-item__title"><a href="' . esc_url($item->url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($item->title) . '</a></h3>';

            $thumbnail = $item->thumbnailMediaId !== null ? $media->find($item->thumbnailMediaId) : null;

            if ($thumbnail !== null) {
                $html .= '<img class="lp-link-directory-item__thumbnail" src="' . esc_url($media->url($thumbnail)) . '" alt="' . esc_attr((string) ($thumbnail['alt_text'] ?? $item->title)) . '">';
            }

            if ($item->description !== '') {
                $html .= '<div class="lp-link-directory-item__description">' . $content->render($item->description, $item->descriptionFormat) . '</div>';
            }

            // Was plain, unlinked text (LPP-027) — a visitor had to copy/paste the URL by hand even though the title above already links to it.
            $html .= '<p class="lp-link-directory-item__url"><a href="' . esc_url($item->url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($item->url) . '</a></p>';
            $html .= '</article>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * The "show all categories" variant: every top-level category and its
     * sub-categories, each with its own direct link count. Each name links
     * to that category's own drilldown view (renderCategoryDetail(), via
     * CATEGORY_QUERY_PARAM on the current page) — previously plain text,
     * leaving no way to actually reach a category's links short of the
     * admin building a separate page per category (LPP-027).
     */
    private function renderCategoryList(LinkDirectoryCategoryService $categories): string
    {
        $rows = $categories->listAllForTree();

        if ($rows === []) {
            return '';
        }

        $html = '<ul class="lp-link-directory-categories">';

        foreach ($rows as $row) {
            $indent = str_repeat(' lp-link-directory-categories__item--indent', $row['depth']);
            $count = $categories->linkCount($row['category']->id);

            $html .= '<li class="lp-link-directory-categories__item' . $indent . '">'
                . '<a href="' . esc_url($this->categoryLinkUrl($row['category']->id)) . '">' . esc_html($row['category']->name) . '</a>'
                . ' (' . $count . ')'
                . '</li>';
        }

        $html .= '</ul>';

        return $html;
    }

    private function media(): MediaService
    {
        if ($this->injectedMedia !== null) {
            return $this->injectedMedia;
        }

        $config = require LUMORA_ROOT . '/config/config.php';
        $database = Database::connect(
            host: (string) $config['db_host'],
            database: (string) $config['db_name'],
            username: (string) $config['db_user'],
            password: (string) $config['db_password'],
            port: (int) ($config['db_port'] ?? 3306),
        );
        $tablePrefix = (string) $config['table_prefix'];

        return new MediaService($database, $tablePrefix, LUMORA_ROOT . '/content/uploads', \LumoraPress\Core\Http\BasePath::get() . '/content/uploads');
    }

    /**
     * @param string $rawAttributes e.g. ` category="Fansites" link_id="4"`
     * @return array<string, string>
     */
    private function parseAttributes(string $rawAttributes): array
    {
        $attributes = [];

        if (preg_match_all('/([a-zA-Z_]+)="([^"]*)"/', $rawAttributes, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes[$match[1]] = $match[2];
            }
        }

        return $attributes;
    }

    /**
     * @return array{0: LinkService, 1: LinkDirectoryCategoryService}
     */
    private function services(): array
    {
        if ($this->injectedLinks !== null && $this->injectedCategories !== null) {
            return [$this->injectedLinks, $this->injectedCategories];
        }

        $config = require LUMORA_ROOT . '/config/config.php';
        $database = Database::connect(
            host: (string) $config['db_host'],
            database: (string) $config['db_name'],
            username: (string) $config['db_user'],
            password: (string) $config['db_password'],
            port: (int) ($config['db_port'] ?? 3306),
        );
        $tablePrefix = (string) $config['table_prefix'];

        return [new LinkService($database, $tablePrefix), new LinkDirectoryCategoryService($database, $tablePrefix)];
    }
}
