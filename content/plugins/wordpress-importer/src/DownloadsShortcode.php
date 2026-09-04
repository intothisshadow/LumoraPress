<?php

/**
 * Renders WordPress Simple Download Monitor's [sdm_show_dl_from_category]/[sdm_download]/[sdm_latest_downloads] shortcodes using content this plugin's importer already migrated.
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

namespace LumoraPress\Plugins\WordPressImporter;

use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Http\BasePath;
use LumoraPress\Models\Folder;
use LumoraPress\Services\ContentImportRegistry;
use LumoraPress\Services\FolderService;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\RedirectService;

/**
 * The display half of `WordPressImportService::importDownloads()`: recognizes the SDM
 * shortcodes sitting inert in migrated content and replaces them with real rendered markup.
 *
 * - `[sdm_show_dl_from_category]` — every download filed under one category.
 * - `[sdm_download id="123"]` — a single download, resolved via `ContentImportRegistry::
 *   existingLocalId()` through 'media'/'redirect' entries, not 'download', so this class
 *   stays independent of the Downloads plugin's existence.
 * - `[sdm_latest_downloads number="4"]` — the N most recently added downloads.
 *
 * Registered on `content_html`; opens its own Database connection only when content
 * actually contains a shortcode. A category is matched by slugifying each Folder's name.
 */
final class DownloadsShortcode
{
    private const PATTERN_CATEGORY = '/\[sdm_show_dl_from_category([^\]]*)\]/i';
    private const PATTERN_SINGLE = '/\[sdm_download(\s[^\]]*)?\]/i';
    private const PATTERN_LATEST = '/\[sdm_latest_downloads(\s[^\]]*)?\]/i';

    // Must equal WordPressImportService::SOURCE — kept as a separate local
    // copy so this class stays loadable without that class's heavier dependency chain.
    private const IMPORT_SOURCE = 'wordpress_import';

    private ?Database $database = null;
    private string $tablePrefix = '';

    /**
     * All four optional and given together, or none — tests use SQLite
     * fixtures; the plugin's bootstrap passes none, so services()/registry() lazily open a real connection.
     */
    public function __construct(
        private readonly ?FolderService $injectedFolders = null,
        private readonly ?MediaService $injectedMedia = null,
        private readonly ?RedirectService $injectedRedirects = null,
        private readonly ?ContentImportRegistry $injectedRegistry = null,
    ) {
    }

    public function renderShortcodes(string $html): string
    {
        if (str_contains($html, '[sdm_show_dl_from_category')) {
            $html = preg_replace_callback(
                self::PATTERN_CATEGORY,
                fn (array $matches): string => $this->renderCategory($this->parseAttributes($matches[1] ?? '')),
                $html,
            ) ?? $html;
        }

        if (str_contains($html, '[sdm_download')) {
            $html = preg_replace_callback(
                self::PATTERN_SINGLE,
                fn (array $matches): string => $this->renderSingle($this->parseAttributes($matches[1] ?? '')),
                $html,
            ) ?? $html;
        }

        if (str_contains($html, '[sdm_latest_downloads')) {
            $html = preg_replace_callback(
                self::PATTERN_LATEST,
                fn (array $matches): string => $this->renderLatest($this->parseAttributes($matches[1] ?? '')),
                $html,
            ) ?? $html;
        }

        return $html;
    }

    /**
     * @param array<string, string> $attributes
     */
    private function renderCategory(array $attributes): string
    {
        $slug = trim($attributes['category_slug'] ?? '');

        if ($slug === '') {
            return '';
        }

        [$folders, $media, $redirects] = $this->services();
        $folder = $this->findFolderBySlug($folders, $slug);

        if ($folder === null) {
            return '';
        }

        $showSize = ($attributes['show_size'] ?? '0') === '1';
        $items = $this->folderItems($media, $redirects, $folder->id, $showSize);

        if ($items === []) {
            return '';
        }

        usort($items, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        $html = '<div class="lp-downloads-list">';
        $html .= '<h3 class="lp-downloads-list__title">' . esc_html($folder->name) . '</h3>';
        $html .= $this->renderItems($items);
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<string, string> $attributes
     */
    private function renderSingle(array $attributes): string
    {
        $externalId = trim($attributes['id'] ?? '');

        if ($externalId === '') {
            return '';
        }

        [, $media, $redirects] = $this->services();
        $item = $this->resolveItemByExternalId($media, $redirects, $externalId);

        if ($item === null) {
            return '';
        }

        return '<div class="lp-downloads-list">' . $this->renderItems([$item]) . '</div>';
    }

    /**
     * The N most recently added downloads, optionally scoped to one category.
     * Media and Redirect rows are merged and re-sorted here since they're
     * two separate tables with no shared "date added" query.
     *
     * @param array<string, string> $attributes
     */
    private function renderLatest(array $attributes): string
    {
        $number = max(1, (int) ($attributes['number'] ?? 5));
        $slug = trim($attributes['category_slug'] ?? '');
        [$folders, $media, $redirects] = $this->services();

        $folderId = null;

        if ($slug !== '') {
            $folder = $this->findFolderBySlug($folders, $slug);

            if ($folder === null) {
                return '';
            }

            $folderId = $folder->id;
        }

        $showSize = ($attributes['show_size'] ?? '0') === '1';
        $candidates = [];

        $mediaFilters = $folderId !== null ? ['folderIds' => [$folderId]] : [];

        foreach ($media->query($mediaFilters, 500, 0)['items'] as $item) {
            $candidates[] = [
                'name' => (string) $item['file_name'],
                'description' => trim((string) ($item['description'] ?? '')),
                'url' => site_url('media/' . (int) $item['id'] . '/download'),
                'size' => $showSize ? $this->formatBytes((int) $item['file_size']) : null,
                'sortKey' => (string) $item['uploaded_at'],
            ];
        }

        $redirectRows = $folderId !== null ? $redirects->listByFolder($folderId) : $redirects->listAll();

        foreach ($redirectRows as $redirect) {
            $candidates[] = [
                'name' => $this->titleFromSourcePath((string) $redirect['source_path']),
                'description' => '',
                'url' => site_url((string) $redirect['source_path']),
                'size' => null,
                'sortKey' => (string) $redirect['created_at'],
            ];
        }

        if ($candidates === []) {
            return '';
        }

        usort($candidates, static fn (array $a, array $b): int => strcmp($b['sortKey'], $a['sortKey']));
        $items = array_map(
            static fn (array $item): array => ['name' => $item['name'], 'description' => $item['description'], 'url' => $item['url'], 'size' => $item['size']],
            array_slice($candidates, 0, $number),
        );

        return '<div class="lp-downloads-list">' . $this->renderItems($items) . '</div>';
    }

    /**
     * A single Media/Redirect external id resolved back to a renderable
     * item — tries 'media' first, then 'redirect'; an id resolves in only one of the two.
     *
     * @return array{name: string, description: string, url: string, size: ?string}|null
     */
    private function resolveItemByExternalId(MediaService $media, RedirectService $redirects, string $externalId): ?array
    {
        $registry = $this->registry();
        $mediaId = $registry->existingLocalId(self::IMPORT_SOURCE, 'media', $externalId);

        if ($mediaId !== null) {
            $row = $media->find($mediaId);

            if ($row !== null) {
                return [
                    'name' => (string) $row['file_name'],
                    'description' => trim((string) ($row['description'] ?? '')),
                    'url' => site_url('media/' . (int) $row['id'] . '/download'),
                    'size' => $this->formatBytes((int) $row['file_size']),
                ];
            }
        }

        $redirectId = $registry->existingLocalId(self::IMPORT_SOURCE, 'redirect', $externalId);

        if ($redirectId !== null) {
            $row = $redirects->find($redirectId);

            if ($row !== null) {
                return [
                    'name' => $this->titleFromSourcePath((string) $row['source_path']),
                    'description' => '',
                    'url' => site_url((string) $row['source_path']),
                    'size' => null,
                ];
            }
        }

        return null;
    }

    /**
     * @return array<int, array{name: string, description: string, url: string, size: ?string}>
     */
    private function folderItems(MediaService $media, RedirectService $redirects, int $folderId, bool $showSize): array
    {
        $items = [];

        foreach ($media->query(['folderIds' => [$folderId]], 500, 0)['items'] as $item) {
            $items[] = [
                'name' => (string) $item['file_name'],
                'description' => trim((string) ($item['description'] ?? '')),
                'url' => site_url('media/' . (int) $item['id'] . '/download'),
                'size' => $showSize ? $this->formatBytes((int) $item['file_size']) : null,
            ];
        }

        foreach ($redirects->listByFolder($folderId) as $redirect) {
            $items[] = [
                'name' => $this->titleFromSourcePath((string) $redirect['source_path']),
                'description' => '',
                'url' => site_url((string) $redirect['source_path']),
                'size' => null,
            ];
        }

        return $items;
    }

    /**
     * The shared `<ul>` items markup — renderCategory() wraps this with a
     * folder-name `<h3>`; renderSingle()/renderLatest() don't.
     *
     * @param array<int, array{name: string, description: string, url: string, size: ?string}> $items
     */
    private function renderItems(array $items): string
    {
        $html = '<ul class="lp-downloads-list__items">';

        foreach ($items as $item) {
            $html .= '<li class="lp-downloads-list__item">';
            $html .= '<a class="lp-downloads-list__link" href="' . esc_url($item['url']) . '">' . esc_html($item['name']) . '</a>';

            if ($item['size'] !== null) {
                $html .= ' <span class="lp-downloads-list__size">(' . esc_html($item['size']) . ')</span>';
            }

            if ($item['description'] !== '') {
                // Media descriptions are plain text, editable by any Author-level
                // user — escape rather than trust as pre-sanitized HTML.
                $html .= '<div class="lp-downloads-list__description">' . esc_html($item['description']) . '</div>';
            }

            $html .= '</li>';
        }

        $html .= '</ul>';

        return $html;
    }

    private function findFolderBySlug(FolderService $folders, string $slug): ?Folder
    {
        foreach ($folders->listAll() as $folder) {
            if ($this->slugify($folder->name) === $slug) {
                return $folder;
            }
        }

        return null;
    }

    // Public so wordpress-importer.php's shortcode-picker registration can
    // compute the same category_slug choices, without duplicating the rule.
    public function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    /**
     * "/downloads/poudre-et-plume-ao3-site-skin" -> "Poudre Et Plume Ao3 Site Skin".
     */
    private function titleFromSourcePath(string $sourcePath): string
    {
        $slug = basename($sourcePath);

        return ucwords(str_replace('-', ' ', $slug));
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
     * @param string $rawAttributes e.g. ` category_slug="game-of-thrones" fancy="1" show_size="1"`
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
     * @return array{0: FolderService, 1: MediaService, 2: RedirectService}
     */
    private function services(): array
    {
        if ($this->injectedFolders !== null && $this->injectedMedia !== null && $this->injectedRedirects !== null) {
            return [$this->injectedFolders, $this->injectedMedia, $this->injectedRedirects];
        }

        [$database, $tablePrefix] = $this->connection();

        return [
            new FolderService($database, $tablePrefix),
            new MediaService($database, $tablePrefix, LUMORA_ROOT . '/content/uploads', BasePath::get() . '/content/uploads'),
            new RedirectService($database, $tablePrefix),
        ];
    }

    // Only needed by renderSingle() — the category/latest variants never resolve an external id.
    private function registry(): ContentImportRegistry
    {
        if ($this->injectedRegistry !== null) {
            return $this->injectedRegistry;
        }

        [$database, $tablePrefix] = $this->connection();

        return new ContentImportRegistry($database, $tablePrefix);
    }

    /**
     * Shared by services()/registry() so a request needing both opens one
     * Database connection, not two — cached since renderSingle()/
     * renderCategory()/renderLatest() can each fire multiple times per call.
     *
     * @return array{0: Database, 1: string}
     */
    private function connection(): array
    {
        if ($this->database === null) {
            $config = require LUMORA_ROOT . '/config/config.php';
            $this->database = Database::connect(
                host: (string) $config['db_host'],
                database: (string) $config['db_name'],
                username: (string) $config['db_user'],
                password: (string) $config['db_password'],
                port: (int) ($config['db_port'] ?? 3306),
            );
            $this->tablePrefix = (string) $config['table_prefix'];
        }

        return [$this->database, $this->tablePrefix];
    }
}
