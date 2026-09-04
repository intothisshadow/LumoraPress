<?php

/**
 * Renders the `[lumora_downloads]` shortcode using this plugin's own downloads table.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\Downloads;

use LumoraPress\Core\Content\HtmlSanitizer;
use LumoraPress\Core\Content\MarkdownParser;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\Http\BasePath;
use LumoraPress\Services\ContentRenderer;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\RedirectService;

/**
 * A same-named but unrelated class to the WordPress Importer plugin's own
 * `[sdm_show_dl_from_category]` DownloadsShortcode — different namespaces.
 *
 * Registered on the content_html filter (see downloads.php); needs real
 * database-backed services a plugin's load-time code can't reach yet, so it
 * opens its own Database connection rather than waiting for $kernel.
 */
final class DownloadsShortcode
{
    private const PATTERN = '/\[lumora_downloads([^\]]*)\]/i';

    /**
     * $injectedDownloads/$injectedCategories are given together or not at
     * all (tests use SQLite fixtures; the plugin's bootstrap passes none,
     * so services() lazily opens a real connection). $injectedContent and
     * $injectedMasker are each independent, letting a test cover rendering
     * or URL-masking without standing up the full fixture pair.
     */
    public function __construct(
        private readonly ?DownloadService $injectedDownloads = null,
        private readonly ?DownloadCategoryService $injectedCategories = null,
        private readonly ?ContentRenderer $injectedContent = null,
        private readonly ?DownloadMediaUrlMasker $injectedMasker = null,
    ) {
    }

    public function renderShortcodes(string $html): string
    {
        if (!str_contains($html, '[lumora_downloads')) {
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
     * Three variants, checked in order: `download_id` (single download by
     * id), `count` (newest N, optionally filtered to a category, sorted
     * newest-first), or neither (list a category's downloads, alphabetical).
     *
     * @param array<string, string> $attributes
     */
    private function renderOne(array $attributes): string
    {
        [$downloads, $categories] = $this->services();

        if (($attributes['download_id'] ?? '') !== '') {
            $download = $downloads->findById((int) $attributes['download_id']);

            if ($download === null || $download->trashedAt !== null) {
                return '';
            }

            $showSize = ($attributes['show_size'] ?? '0') === '1';

            return $this->renderList(null, [$download], $showSize);
        }

        if (($attributes['count'] ?? '') !== '') {
            $category = $this->resolveCategory($categories, $attributes);
            $items = $downloads->listNewest($category?->id, (int) $attributes['count']);

            if ($items === []) {
                return '';
            }

            $showSize = ($attributes['show_size'] ?? '0') === '1';

            return $this->renderList($category, $items, $showSize);
        }

        $category = $this->resolveCategory($categories, $attributes);

        if ($category === null) {
            return '';
        }

        $items = $downloads->listByCategory($category->id);

        if ($items === []) {
            return '';
        }

        $showSize = ($attributes['show_size'] ?? '0') === '1';

        return $this->renderList($category, $items, $showSize);
    }

    /**
     * A private HookManager, not the site's shared one — reusing the shared
     * one would re-trigger this class's own content_html callback mid-render,
     * since ContentRenderer::render() ends by re-running that filter.
     */
    private function content(): ContentRenderer
    {
        return $this->injectedContent ?? new ContentRenderer(new MarkdownParser(), new HtmlSanitizer(), new HookManager());
    }

    /**
     * See DownloadMediaUrlMasker's own docblock. A shortcode-render path
     * has no $kernel to reuse, so this opens its own standalone MediaService.
     */
    private function masker(): DownloadMediaUrlMasker
    {
        if ($this->injectedMasker !== null) {
            return $this->injectedMasker;
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
        $media = new MediaService($database, $tablePrefix, LUMORA_ROOT . '/content/uploads', BasePath::get() . '/content/uploads');

        return new DownloadMediaUrlMasker($media, BasePath::get() . '/content/uploads');
    }

    private function uploadsUrlPrefix(): string
    {
        return rtrim(BasePath::get() . '/content/uploads', '/') . '/';
    }

    /**
     * `category_id` (an exact DownloadCategory id) wins if given;
     * otherwise `category` is matched case-insensitively against the
     * category's own name — DownloadCategory has no slug column. Neither
     * attribute given, or no matching category, both resolve to null.
     *
     * @param array<string, string> $attributes
     */
    private function resolveCategory(DownloadCategoryService $categories, array $attributes): ?DownloadCategory
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
     * $category is null for the `download_id` single-item variant and the
     * category-less "newest across all downloads" variant — the heading
     * is only rendered when a category was actually resolved.
     *
     * @param array<int, Download> $items
     */
    private function renderList(?DownloadCategory $category, array $items, bool $showSize): string
    {
        // A Download with no file/URL attached yet resolves to an empty
        // Download::$url — never shown publicly, since a dead link helps no visitor.
        $items = array_values(array_filter($items, static fn (Download $item): bool => $item->url !== ''));

        if ($items === []) {
            return '';
        }

        $content = $this->content();

        $html = '<div class="lp-downloads-list">';

        if ($category !== null) {
            $html .= '<h3 class="lp-downloads-list__title">' . esc_html($category->name) . '</h3>';
        }

        $html .= '<ul class="lp-downloads-list__items">';

        foreach ($items as $item) {
            $html .= '<li class="lp-downloads-list__item">';
            $html .= '<a class="lp-downloads-list__link" href="' . esc_url($item->url) . '">' . esc_html($item->title) . '</a>';

            if ($showSize && $item->fileSizeBytes !== null) {
                $html .= ' <span class="lp-downloads-list__size">(' . esc_html($this->formatBytes($item->fileSizeBytes)) . ')</span>';
            }

            if ($item->description !== '') {
                // masker()->mask() rewrites any embedded image URL that still points
                // at the real upload path; guarded by a cheap string check first.
                $rendered = $content->render($item->description, $item->descriptionFormat);

                if (str_contains($rendered, $this->uploadsUrlPrefix())) {
                    $rendered = $this->masker()->mask($rendered);
                }

                $html .= '<div class="lp-downloads-list__description">' . $rendered . '</div>';
            }

            $html .= '</li>';
        }

        $html .= '</ul></div>';

        return $html;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }

    /**
     * @param string $rawAttributes e.g. ` category="AO3 Site Skins" show_size="1"`
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
     * @return array{0: DownloadService, 1: DownloadCategoryService}
     */
    private function services(): array
    {
        if ($this->injectedDownloads !== null && $this->injectedCategories !== null) {
            return [$this->injectedDownloads, $this->injectedCategories];
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
        $categories = new DownloadCategoryService($database, $tablePrefix);
        $media = new MediaService($database, $tablePrefix, LUMORA_ROOT . '/content/uploads', BasePath::get() . '/content/uploads');
        $redirects = new RedirectService($database, $tablePrefix);
        $downloads = new DownloadService($database, $tablePrefix, $media, $redirects, $categories);

        return [$downloads, $categories];
    }
}
