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
 * NextGEN gallery/album ids are recorded in `ContentImportRegistry` under
 * content type `'folder'` by `createOrUpdateFolder()`, so a NextGEN id
 * embedded in a shortcode resolves straight to its Folder.
 *
 * Only *rewrites* matched shortcode text into `[lumora_folder_gallery
 * folder_id="Y" link="full"]` — it never renders a gallery itself. Runs at
 * priority 5, lower than `FolderGalleryShortcode`'s default 10, so this
 * rewrite lands before that class gets its turn in the same filter pass.
 *
 * Covers the common NextGEN forms: `[nggallery]`, `[album]`, `[ngg_images]`,
 * and the older `[ngg]` form. `[nggtags]`/`[ngg_slideshow]` are out of scope
 * and stay flagged as an import warning instead.
 */
final class NextGenGalleryShortcode
{
    private const IMPORT_SOURCE = 'wordpress_import';

    private const PATTERN_NGGALLERY = '/\[nggallery(\s[^\]]*)?\]/i';
    private const PATTERN_ALBUM = '/\[album(\s[^\]]*)?\]/i';
    private const PATTERN_NGG_IMAGES = '/\[ngg_images(\s[^\]]*)?\]/i';

    // Requires whitespace immediately after "ngg" so this never matches
    // `[nggallery ...]` or `[ngg_images ...]`.
    private const PATTERN_NGG_SHORT = '/\[ngg(\s[^\]]*)?\]/i';

    private ?Database $database = null;
    private string $tablePrefix = '';

    // Optional, injected for tests (SQLite fixture); the plugin's bootstrap
    // passes none, so registry() lazily opens a real connection on first use.
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
     * `[ngg_images ... container_ids="1,2"]` / `[ngg ... ids="1,2" ...]` —
     * the `source`/`src` attribute doesn't change resolution, since both
     * gallery and album ids are recorded under the same `'folder'` type.
     * Every other NextGEN display attribute is ignored.
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
