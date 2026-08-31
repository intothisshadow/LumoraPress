<?php

/**
 * Renders WordPress Simple Download Monitor's [sdm_show_dl_from_category]/[sdm_download]/[sdm_latest_downloads] shortcodes using content this plugin's importer already migrated (LPP-004/LPP-007).
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
 * `WordPressImportService::importDownloads()` (LPP-004) files a Simple
 * Download Monitor download into a Media Manager Folder matching its
 * `sdm_categories` term (a real Media item for a locally-hosted file, a
 * Redirect with the same `folder_id` for an external-URL-only one — see
 * that method's own docblock). This class is the display half: it
 * recognizes the shortcodes already sitting, inert, in migrated
 * post/page content, and replaces them with real rendered markup, so a
 * migrated page works without hand-editing:
 *
 * - `[sdm_show_dl_from_category category_slug="..." fancy="1"
 *   show_size="1"]` — every download filed under one category.
 * - `[sdm_download id="123" fancy="1"]` — a single download, embedded
 *   inline wherever SDM's own editor inserted it. `id` is the download's
 *   *original* WordPress post id (Simple Download Monitor stores each
 *   download as its own `sdm_downloads` post) — resolved back to the
 *   Media/Redirect row it became via `ContentImportRegistry::existingLocalId()`
 *   against the same `external_id` `WordPressImportService::importDownloads()`
 *   recorded at import time. Deliberately resolved through the 'media'/
 *   'redirect' registry entries (always recorded) rather than the
 *   'download' one (only recorded when the Downloads plugin, LPP-008,
 *   happened to be active during that import run) — this class stays
 *   independent of that plugin's existence, same as the category
 *   shortcode above already is.
 * - `[sdm_latest_downloads number="4" category_slug="..." fancy="2"]` —
 *   the N most recently added downloads, optionally scoped to one
 *   category the same way the category shortcode resolves one.
 *
 * Registered on the `content_html` filter (see wordpress-importer.php),
 * the same hook Font Awesome's own `[icon]` shortcode uses — but unlike
 * Font Awesome, this needs real database-backed services (Folders,
 * Media, Redirects, the import provenance registry), which a plugin's
 * load-time code can't reach (see DEVELOPER-APIS.md's "Plugin main
 * files load before Kernel exists"). Rather than defer to something the
 * admin view hands $kernel to (there is no such caller for
 * public-facing content rendering), this opens its own independent
 * Database connection from `config/config.php` — the same "a plugin can
 * talk to a database Kernel doesn't already expose" pattern
 * `WordPressSource` uses for the *source* WordPress site, just pointed
 * at this site's own database instead. Only actually opened when a
 * page's content contains one of the three shortcodes at all (checked
 * with a plain `str_contains()` first), so normal page renders pay
 * nothing for this.
 *
 * A category is matched by slugifying each Folder's name and comparing
 * it against the shortcode's `category_slug` attribute — Folders have
 * no slug column of their own (see this ticket's own note in
 * TODO-PLUGINS.md), so this only matches when WordPress's own
 * auto-generated slug equals a simple lowercase-hyphenated version of
 * the category name, true for every category on this project's own
 * real source site but not guaranteed for a hand-edited WordPress slug.
 */
final class DownloadsShortcode
{
    private const PATTERN_CATEGORY = '/\[sdm_show_dl_from_category([^\]]*)\]/i';
    private const PATTERN_SINGLE = '/\[sdm_download(\s[^\]]*)?\]/i';
    private const PATTERN_LATEST = '/\[sdm_latest_downloads(\s[^\]]*)?\]/i';

    /**
     * Must equal WordPressImportService::SOURCE exactly (the `source`
     * value its registry rows are recorded under) — kept as a separate
     * local copy rather than a reference to that class so this one
     * stays loadable (and unit-testable) on its own, without pulling in
     * WordPressImportService's much heavier dependency chain, which
     * itself reaches into the Downloads plugin (see this class's own
     * docblock on staying independent of that plugin's existence).
     */
    private const IMPORT_SOURCE = 'wordpress_import';

    private ?Database $database = null;
    private string $tablePrefix = '';

    /**
     * All four optional and given together, or none — tests construct
     * this with real (SQLite-fixture-backed) service instances so
     * renderShortcodes() never needs a real database connection; the
     * plugin's own bootstrap constructs this with no arguments, so
     * services()/registry() lazily open the real one on first actual use.
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
     * The N most recently added downloads — optionally scoped to one
     * category, resolved the same way renderCategory() resolves one.
     * Media and Redirect rows are merged and re-sorted here since
     * they're two separate tables with no shared "date added" query;
     * each has its own creation-date column ($item['uploaded_at']/
     * $redirect['created_at']) to sort by before slicing to $number.
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
     * A single Media/Redirect external id (Simple Download Monitor's own
     * `sdm_downloads` post id) resolved back to a renderable item. Tries
     * 'media' first (a file-type download), then 'redirect' (a
     * URL-type one) — an id only ever resolves in one of the two,
     * matching how WordPressImportService::importDownloads() branches on
     * $uploadUrl containing '/wp-content/uploads/' or not.
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
     * The shared `<ul>` items markup every shortcode variant renders —
     * renderCategory() wraps this with its own folder-name `<h3>`,
     * renderSingle()/renderLatest() don't (no single folder to name).
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
                // Media descriptions are plain text (no Markdown/HTML format
                // choice like the Downloads plugin's own field), and are
                // editable by any Author-level user — escape rather than
                // trust them as pre-sanitized HTML.
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

    /**
     * Public (rather than the private visibility every other helper here
     * has) so wordpress-importer.php's own LP-110 shortcode-picker
     * registration can compute the same category_slug choices this
     * class matches against in findFolderBySlug() above, without
     * duplicating the slugging rule in two places.
     */
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

    /**
     * Only ever needed by renderSingle() — the category/latest variants
     * never resolve an external id, so they never pay for this.
     */
    private function registry(): ContentImportRegistry
    {
        if ($this->injectedRegistry !== null) {
            return $this->injectedRegistry;
        }

        [$database, $tablePrefix] = $this->connection();

        return new ContentImportRegistry($database, $tablePrefix);
    }

    /**
     * Shared by services()/registry() so a request needing both opens
     * one Database connection, not two — cached on the instance since a
     * single request can call renderShortcodes() only once per page
     * render, but renderSingle()/renderCategory()/renderLatest() can
     * each fire multiple times within that one call (one per shortcode
     * match in the content).
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
