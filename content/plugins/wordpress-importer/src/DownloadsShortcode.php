<?php

/**
 * Renders WordPress Simple Download Monitor's [sdm_show_dl_from_category] shortcode using content this plugin's importer already migrated (LPP-004/LPP-007).
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
use LumoraPress\Services\FolderService;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\RedirectService;

/**
 * `WordPressImportService::importDownloads()` (LPP-004) files a Simple
 * Download Monitor download into a Media Manager Folder matching its
 * `sdm_categories` term (a real Media item for a locally-hosted file, a
 * Redirect with the same `folder_id` for an external-URL-only one — see
 * that method's own docblock). This class is the display half: it
 * recognizes the exact shortcode already sitting, inert, in migrated
 * post/page content — `[sdm_show_dl_from_category
 * category_slug="..." fancy="1" show_size="1"]` — and replaces it with
 * a real rendered list, so a migrated page works without hand-editing.
 *
 * Registered on the `content_html` filter (see wordpress-importer.php),
 * the same hook Font Awesome's own `[icon]` shortcode uses — but unlike
 * Font Awesome, this needs real database-backed services (Folders,
 * Media, Redirects), which a plugin's load-time code can't reach (see
 * DEVELOPER-APIS.md's "Plugin main files load before Kernel exists").
 * Rather than defer to something the admin view hands $kernel to (there
 * is no such caller for public-facing content rendering), this opens
 * its own independent Database connection from `config/config.php` —
 * the same "a plugin can talk to a database Kernel doesn't already
 * expose" pattern `WordPressSource` uses for the *source* WordPress
 * site, just pointed at this site's own database instead. Only actually
 * opened when a page's content contains the shortcode text at all
 * (checked with a plain `str_contains()` first), so normal page renders
 * pay nothing for this.
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
    private const PATTERN = '/\[sdm_show_dl_from_category([^\]]*)\]/i';

    /**
     * All three optional and given together, or none — tests construct
     * this with real (SQLite-fixture-backed) service instances so
     * renderShortcodes() never needs a real database connection; the
     * plugin's own bootstrap constructs this with no arguments, so
     * services() lazily opens the real one on first actual use.
     */
    public function __construct(
        private readonly ?FolderService $injectedFolders = null,
        private readonly ?MediaService $injectedMedia = null,
        private readonly ?RedirectService $injectedRedirects = null,
    ) {
    }

    public function renderShortcodes(string $html): string
    {
        if (!str_contains($html, '[sdm_show_dl_from_category')) {
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
     * @param array<string, string> $attributes
     */
    private function renderOne(array $attributes): string
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
        $items = [];

        foreach ($media->query(['folderIds' => [$folder->id]], 500, 0)['items'] as $item) {
            $items[] = [
                'name' => (string) $item['file_name'],
                'description' => trim((string) ($item['description'] ?? '')),
                'url' => site_url('media/' . (int) $item['id'] . '/download'),
                'size' => $showSize ? $this->formatBytes((int) $item['file_size']) : null,
            ];
        }

        foreach ($redirects->listByFolder($folder->id) as $redirect) {
            $items[] = [
                'name' => $this->titleFromSourcePath((string) $redirect['source_path']),
                'description' => '',
                'url' => site_url((string) $redirect['source_path']),
                'size' => null,
            ];
        }

        if ($items === []) {
            return '';
        }

        usort($items, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $this->renderList($folder, $items);
    }

    /**
     * @param array<int, array{name: string, description: string, url: string, size: ?string}> $items
     */
    private function renderList(Folder $folder, array $items): string
    {
        $html = '<div class="lp-downloads-list">';
        $html .= '<h3 class="lp-downloads-list__title">' . esc_html($folder->name) . '</h3>';
        $html .= '<ul class="lp-downloads-list__items">';

        foreach ($items as $item) {
            $html .= '<li class="lp-downloads-list__item">';
            $html .= '<a class="lp-downloads-list__link" href="' . esc_url($item['url']) . '">' . esc_html($item['name']) . '</a>';

            if ($item['size'] !== null) {
                $html .= ' <span class="lp-downloads-list__size">(' . esc_html($item['size']) . ')</span>';
            }

            if ($item['description'] !== '') {
                $html .= '<div class="lp-downloads-list__description">' . $item['description'] . '</div>';
            }

            $html .= '</li>';
        }

        $html .= '</ul></div>';

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

    private function slugify(string $value): string
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

        $config = require LUMORA_ROOT . '/config/config.php';
        $database = Database::connect(
            host: (string) $config['db_host'],
            database: (string) $config['db_name'],
            username: (string) $config['db_user'],
            password: (string) $config['db_password'],
            port: (int) ($config['db_port'] ?? 3306),
        );
        $tablePrefix = (string) $config['table_prefix'];

        return [
            new FolderService($database, $tablePrefix),
            new MediaService($database, $tablePrefix, LUMORA_ROOT . '/content/uploads', BasePath::get() . '/content/uploads'),
            new RedirectService($database, $tablePrefix),
        ];
    }
}
