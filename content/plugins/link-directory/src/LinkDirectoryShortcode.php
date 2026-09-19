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

        return $this->renderCategoryList($categories);
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
            $html .= '<h3 class="lp-link-directory-item__title"><a href="' . esc_url($item->url) . '">' . esc_html($item->title) . '</a></h3>';

            $thumbnail = $item->thumbnailMediaId !== null ? $media->find($item->thumbnailMediaId) : null;

            if ($thumbnail !== null) {
                $html .= '<img class="lp-link-directory-item__thumbnail" src="' . esc_url($media->url($thumbnail)) . '" alt="' . esc_attr((string) ($thumbnail['alt_text'] ?? $item->title)) . '">';
            }

            if ($item->description !== '') {
                $html .= '<div class="lp-link-directory-item__description">' . $content->render($item->description, $item->descriptionFormat) . '</div>';
            }

            $html .= '<p class="lp-link-directory-item__url">' . esc_html($item->url) . '</p>';
            $html .= '</article>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * The "show all categories" variant: every top-level category and its
     * sub-categories, each with its own direct link count.
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
                . esc_html($row['category']->name) . ' (' . $count . ')'
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
