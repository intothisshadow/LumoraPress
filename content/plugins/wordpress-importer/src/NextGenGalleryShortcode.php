<?php

/**
 * Rewrites migrated WordPress NextGEN Gallery shortcodes into Lumora Press's own [lumora_folder_gallery] shortcode.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.7.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\WordPressImporter;

use LumoraPress\Core\Database\Database;
use LumoraPress\Services\ContentImportRegistry;

/**
 * `WordPressImportService::importNextGenGalleries()` (LPP-004) already
 * files every NextGEN gallery/album's images into a real Media Manager
 * Folder, and `createOrUpdateFolder()` already records each one in
 * `ContentImportRegistry` under content type `'folder'`, keyed by the
 * NextGEN gallery's own `gid` or album's own `id` as the external id —
 * so a NextGEN id embedded in a shortcode resolves straight to its
 * Folder via `ContentImportRegistry::existingLocalId()`, the same
 * lookup shape `DownloadsShortcode::resolveItemByExternalId()` already
 * uses for `'media'`/`'redirect'`.
 *
 * This class only ever *rewrites* matched shortcode text into
 * `[lumora_folder_gallery folder_id="Y" link="full"]` — it never
 * renders a gallery itself, that's core's own
 * `app/Services/FolderGalleryShortcode.php` (LP-122). Registered on
 * `content_html` at **priority 5** (see wordpress-importer.php), lower
 * than `FolderGalleryShortcode`'s default priority 10 — this rewrite
 * must land in the string before `FolderGalleryShortcode::render()`
 * gets its turn in the same `apply_filters('content_html', ...)` pass,
 * since `HookManager::applyFilters()` threads one string through every
 * registered callback in priority order within a single pass rather
 * than re-scanning from scratch after each filter.
 *
 * Covers the common NextGEN shortcode forms actually seen in migrated
 * content: `[nggallery id=...]`/`[nggallery ids="..."]`, `[album
 * id=...]`, `[ngg_images source="galleries|albums"
 * container_ids="..."]`, and the older `[ngg src="galleries|albums"
 * ids="..." ...]` form. Deliberately out of scope: `[nggtags ...]`
 * (deprecated even within NextGEN itself) and `[ngg_slideshow ...]`
 * (an interactive slideshow display with no static-grid equivalent) —
 * both are left exactly as before, still flagged as an import warning
 * by `WordPressImportService::flagUnsupportedShortcodes()`.
 */
final class NextGenGalleryShortcode
{
    private const IMPORT_SOURCE = 'wordpress_import';

    private const PATTERN_NGGALLERY = '/\[nggallery(\s[^\]]*)?\]/i';
    private const PATTERN_ALBUM = '/\[album(\s[^\]]*)?\]/i';
    private const PATTERN_NGG_IMAGES = '/\[ngg_images(\s[^\]]*)?\]/i';

    /**
     * Requires whitespace immediately after "ngg" so this never matches
     * `[nggallery ...]` (a letter follows "ngg", not whitespace) or
     * `[ngg_images ...]` (an underscore follows) — no processing-order
     * dependency on the two patterns above needed.
     */
    private const PATTERN_NGG_SHORT = '/\[ngg(\s[^\]]*)?\]/i';

    private ?Database $database = null;
    private string $tablePrefix = '';

    /**
     * Optional and injected together for tests (a SQLite-fixture-backed
     * ContentImportRegistry); the plugin's own bootstrap constructs this
     * with no arguments, so registry() lazily opens a real connection
     * only once a page's content actually contains one of these
     * shortcodes.
     */
    public function __construct(
        private readonly ?ContentImportRegistry $injectedRegistry = null,
    ) {
    }

    public function rewriteShortcodes(string $html): string
    {
        if (str_contains($html, '[nggallery')) {
            $html = preg_replace_callback(
                self::PATTERN_NGGALLERY,
                fn (array $matches): string => $this->rewriteIds($this->parseAttributes($matches[1] ?? '')),
                $html,
            ) ?? $html;
        }

        if (str_contains($html, '[album')) {
            $html = preg_replace_callback(
                self::PATTERN_ALBUM,
                fn (array $matches): string => $this->rewriteIds($this->parseAttributes($matches[1] ?? '')),
                $html,
            ) ?? $html;
        }

        if (str_contains($html, '[ngg_images')) {
            $html = preg_replace_callback(
                self::PATTERN_NGG_IMAGES,
                fn (array $matches): string => $this->rewriteContainerIds($this->parseAttributes($matches[1] ?? '')),
                $html,
            ) ?? $html;
        }

        if (preg_match(self::PATTERN_NGG_SHORT, $html) === 1) {
            $html = preg_replace_callback(
                self::PATTERN_NGG_SHORT,
                fn (array $matches): string => $this->rewriteContainerIds($this->parseAttributes($matches[1] ?? '')),
                $html,
            ) ?? $html;
        }

        return $html;
    }

    /**
     * `[nggallery id="1"]`/`[nggallery ids="1,2,3"]`/`[album id="1"]` —
     * `ids` wins if present (NextGEN never emits both on the same tag),
     * otherwise falls back to the single `id` attribute.
     *
     * @param array<string, string> $attributes
     */
    private function rewriteIds(array $attributes): string
    {
        $raw = trim($attributes['ids'] ?? '') !== '' ? $attributes['ids'] : ($attributes['id'] ?? '');

        return $this->renderResolvedIds($raw);
    }

    /**
     * `[ngg_images ... container_ids="1,2"]` / `[ngg ... ids="1,2"
     * ...]` — the `source`/`src` attribute (`"galleries"` vs
     * `"albums"`) doesn't change resolution: both a NextGEN gallery id
     * and a NextGEN album id were recorded under the same `'folder'`
     * content type by `createOrUpdateFolder()`, so no branching on it
     * is needed here. `display_type`/`display`/`thumbnail_crop`/every
     * other NextGEN display attribute is ignored — this always renders
     * Lumora Press's own single grid layout.
     *
     * @param array<string, string> $attributes
     */
    private function rewriteContainerIds(array $attributes): string
    {
        $raw = $attributes['container_ids'] ?? ($attributes['ids'] ?? '');

        return $this->renderResolvedIds($raw);
    }

    private function renderResolvedIds(string $rawIds): string
    {
        $rawIds = trim($rawIds);

        if ($rawIds === '') {
            return '';
        }

        $registry = $this->registry();
        $blocks = '';

        foreach (explode(',', $rawIds) as $rawId) {
            $externalId = trim($rawId);

            if ($externalId === '' || !ctype_digit($externalId)) {
                continue;
            }

            $folderId = $registry->existingLocalId(self::IMPORT_SOURCE, 'folder', $externalId);

            if ($folderId === null) {
                continue;
            }

            $blocks .= '[lumora_folder_gallery folder_id="' . $folderId . '" link="full"]';
        }

        return $blocks;
    }

    /**
     * Unlike the other shortcode classes in this codebase, NextGEN's own
     * legacy attribute syntax is frequently unquoted (`[nggallery
     * id=1]`, not `id="1"`), so both forms are matched here.
     *
     * @param string $rawAttributes e.g. ` id=1` or ` ids="1,2,3" template=caption`
     * @return array<string, string>
     */
    private function parseAttributes(string $rawAttributes): array
    {
        $attributes = [];

        if (preg_match_all('/([a-zA-Z_]+)=(?:"([^"]*)"|(\S+))/', $rawAttributes, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes[$match[1]] = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
            }
        }

        return $attributes;
    }

    private function registry(): ContentImportRegistry
    {
        if ($this->injectedRegistry !== null) {
            return $this->injectedRegistry;
        }

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

        return new ContentImportRegistry($this->database, $this->tablePrefix);
    }
}
