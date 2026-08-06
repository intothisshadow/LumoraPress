<?php

/**
 * Tracks which core file paths belong to the application itself, as distinct from user data, for the update pipeline.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

/**
 * Tracks which top-level `corePaths` entries (see UpdateService's
 * docblock — the fixed list of app/, admin/, include/, etc. an update
 * overlays) were in effect as of the last successful install or restore.
 *
 * This exists for exactly one scenario: `$corePaths` is a value only ever
 * set by Lumora Press's own bootstrap.php, never derived from an uploaded
 * release ZIP — so if a future release restructures what counts as core
 * (drops an entry, or renames one), the old entry is never revisited by
 * anything and would sit on disk forever. It is *not* needed for files
 * changing *inside* a corePath between versions — UpdateService::overlayPath()
 * already deletes each corePath entry wholesale before replacing it, so
 * nothing can go stale at that finer grain.
 *
 * Same on-disk-JSON convention as CacheManager's purge log: a private
 * path-suffix constant, read/write helpers that fail safe (an empty/
 * missing/corrupt file behaves like "nothing tracked yet") rather than
 * throwing — a corrupt manifest must never be treated as license to
 * delete things.
 */
final class UpdateManifest
{
    private const PATH_SUFFIX = '/storage/updates/core-manifest.json';

    public function __construct(private readonly string $installRoot)
    {
    }

    /**
     * @return array<int, string>
     */
    public function read(): array
    {
        $path = $this->path();

        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    /**
     * @param array<int, string> $corePaths
     */
    public function write(array $corePaths): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return;
        }

        file_put_contents($path, json_encode(array_values($corePaths)));
    }

    private function path(): string
    {
        return rtrim($this->installRoot, '/') . self::PATH_SUFFIX;
    }
}
