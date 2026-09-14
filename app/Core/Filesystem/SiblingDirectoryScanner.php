<?php

/**
 * Lists real subdirectories of a given parent directory, optionally filtered to ones containing a marker file/path.
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.15.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Filesystem;

/**
 * Shared scan behind every "Discover" server-filepath control in this
 * app — originally `MediaImportService::discoverCandidateDirectories()`
 * (LP-121), generalized here (LP-169) so the WordPress Importer/Lumora
 * Gallery Shortcodes/Visitor Stats plugins can reuse the same scandir/
 * realpath/dotfile-skip logic instead of each reimplementing it, with an
 * optional $markerRelativePath so a caller can ask for only the
 * directories that plausibly *are* the thing it's looking for (e.g. only
 * siblings containing their own `wp-config.php`) rather than every
 * unrelated directory next to this install.
 */
final class SiblingDirectoryScanner
{
    /**
     * @param array<int, string> $exclude absolute paths to leave out (e.g. this install's own root)
     * @return array<int, string> resolved absolute directory paths, sorted
     */
    public static function scan(
        string $scanParent,
        ?string $markerRelativePath = null,
        array $exclude = [],
        int $limit = 200,
    ): array {
        $resolvedParent = realpath($scanParent);

        if ($resolvedParent === false || !is_dir($resolvedParent)) {
            return [];
        }

        $entries = scandir($resolvedParent);

        if ($entries === false) {
            return [];
        }

        $resolvedExclude = array_filter(array_map('realpath', $exclude), static fn (string|false $path): bool => $path !== false);

        $candidates = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $resolvedPath = realpath($resolvedParent . '/' . $entry);

            if ($resolvedPath === false || !is_dir($resolvedPath) || in_array($resolvedPath, $resolvedExclude, true)) {
                continue;
            }

            if ($markerRelativePath !== null && !file_exists($resolvedPath . '/' . $markerRelativePath)) {
                continue;
            }

            $candidates[] = $resolvedPath;
        }

        sort($candidates);

        return array_slice($candidates, 0, $limit);
    }
}
