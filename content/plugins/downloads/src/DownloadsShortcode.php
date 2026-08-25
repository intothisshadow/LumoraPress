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
use LumoraPress\Models\Folder;
use LumoraPress\Services\ContentRenderer;
use LumoraPress\Services\FolderService;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\RedirectService;

/**
 * A fresh shortcode owned by this plugin — not the WordPress Importer
 * plugin's own `[sdm_show_dl_from_category]` (`DownloadsShortcode` in
 * `content/plugins/wordpress-importer/src/`, kept working as-is for
 * already-migrated content; the two are unrelated classes with the same
 * name in different namespaces, by design, mirroring that plugin's own
 * pattern rather than reusing its syntax).
 *
 * Registered on the `content_html` filter (see downloads.php), the same
 * hook Font Awesome's `[icon]` shortcode and the WordPress Importer's
 * own shortcode both use — but like that one, this needs real
 * database-backed services, which a plugin's load-time code can't reach
 * (see DEVELOPER-APIS.md's "Plugin main files load before Kernel
 * exists"). Opens its own independent Database connection from
 * config/config.php rather than waiting for $kernel, the same pattern
 * that class already established. Constructed without a ThumbnailService
 * (DownloadService's own optional dependency, only needed by its
 * upload path) since this only ever reads.
 */
final class DownloadsShortcode
{
    private const PATTERN = '/\[lumora_downloads([^\]]*)\]/i';

    /**
     * $injectedDownloads/$injectedFolders are optional and given
     * together, or none — tests construct this with real
     * (SQLite-fixture-backed) service instances so renderShortcodes()
     * never needs a real database connection; the plugin's own
     * bootstrap constructs this with no arguments, so services() lazily
     * opens the real one on first actual use. $injectedContent is
     * accepted independently of the pair above (a description's
     * Markdown/HTML rendering, LPP-010, needs no database access at
     * all) so a test can cover rendering without also standing up the
     * SQLite fixtures the other two require.
     */
    public function __construct(
        private readonly ?DownloadService $injectedDownloads = null,
        private readonly ?FolderService $injectedFolders = null,
        private readonly ?ContentRenderer $injectedContent = null,
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
     * @param array<string, string> $attributes
     */
    private function renderOne(array $attributes): string
    {
        [$downloads, $folders] = $this->services();
        $folder = $this->resolveFolder($folders, $attributes);

        if ($folder === null) {
            return '';
        }

        $items = $downloads->listByFolder($folder->id);

        if ($items === []) {
            return '';
        }

        $showSize = ($attributes['show_size'] ?? '0') === '1';

        return $this->renderList($folder, $items, $showSize);
    }

    /**
     * A private, database-free ContentRenderer for turning a Download's
     * Markdown/HTML-format description into safe HTML (LPP-010) —
     * deliberately never the site's own shared HookManager instance:
     * this class is itself a `content_html` filter callback (see
     * downloads.php), and ContentRenderer::render() ends by re-running
     * that same filter. Reusing the shared HookManager here would mean
     * every rendered description re-triggers renderShortcodes() (and
     * every other `content_html` listener) mid-callback. A fresh,
     * private HookManager has nothing registered on it, so that last
     * `applyFilters()` call inside render() is a safe no-op — the
     * Markdown parsing and HtmlSanitizer XSS boundary (the part that
     * actually matters for untrusted stored content) still run in full.
     */
    private function content(): ContentRenderer
    {
        return $this->injectedContent ?? new ContentRenderer(new MarkdownParser(), new HtmlSanitizer(), new HookManager());
    }

    /**
     * `category_id` (an exact Folder id) wins if given; otherwise
     * `category` is matched case-insensitively against the Folder's own
     * name — Folders have no slug column, the same constraint the
     * WordPress Importer's own shortcode already works around (there,
     * by slugifying the name and matching against `category_slug`).
     * Neither attribute given, or no matching Folder, both render
     * nothing.
     *
     * @param array<string, string> $attributes
     */
    private function resolveFolder(FolderService $folders, array $attributes): ?Folder
    {
        $categoryId = (int) ($attributes['category_id'] ?? 0);

        if ($categoryId > 0) {
            return $folders->findById($categoryId);
        }

        $categoryName = trim($attributes['category'] ?? '');

        if ($categoryName === '') {
            return null;
        }

        foreach ($folders->listAll() as $folder) {
            if (strcasecmp($folder->name, $categoryName) === 0) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * @param array<int, Download> $items
     */
    private function renderList(Folder $folder, array $items, bool $showSize): string
    {
        $content = $this->content();

        $html = '<div class="lp-downloads-list">';
        $html .= '<h3 class="lp-downloads-list__title">' . esc_html($folder->name) . '</h3>';
        $html .= '<ul class="lp-downloads-list__items">';

        foreach ($items as $item) {
            $html .= '<li class="lp-downloads-list__item">';
            $html .= '<a class="lp-downloads-list__link" href="' . esc_url($item->url) . '">' . esc_html($item->title) . '</a>';

            if ($showSize && $item->fileSizeBytes !== null) {
                $html .= ' <span class="lp-downloads-list__size">(' . esc_html($this->formatBytes($item->fileSizeBytes)) . ')</span>';
            }

            if ($item->description !== '') {
                // Rendered per its own stored $descriptionFormat (LPP-010)
                // — the same Markdown/HTML/Plain branching a post/page's
                // content already gets, since the Description field now
                // uses that same shared editor.
                $html .= '<div class="lp-downloads-list__description">' . $content->render($item->description, $item->descriptionFormat) . '</div>';
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
     * @return array{0: DownloadService, 1: FolderService}
     */
    private function services(): array
    {
        if ($this->injectedDownloads !== null && $this->injectedFolders !== null) {
            return [$this->injectedDownloads, $this->injectedFolders];
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
        $folders = new FolderService($database, $tablePrefix);
        $media = new MediaService($database, $tablePrefix, LUMORA_ROOT . '/content/uploads', BasePath::get() . '/content/uploads');
        $redirects = new RedirectService($database, $tablePrefix);
        $downloads = new DownloadService($database, $tablePrefix, $media, $redirects, $folders);

        return [$downloads, $folders];
    }
}
