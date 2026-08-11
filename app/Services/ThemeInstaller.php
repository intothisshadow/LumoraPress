<?php

/**
 * Validates and installs, or updates in place, a theme ZIP directly into
 * content/themes/{slug} (LP-034/LP-081).
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

use LumoraPress\Core\Theme\ThemeInfo;
use LumoraPress\Core\Theme\ThemeRegistry;
use RuntimeException;
use ZipArchive;

/**
 * Validates and installs, or updates in place, a theme ZIP directly into
 * content/themes/{slug} (LP-034/LP-081). Deliberately much simpler than
 * UpdatePackageValidator/UpdateService (LP-026): a theme install/update
 * only ever touches one self-contained directory — it never overlays live
 * core files — so there is no migration/checksum/active-user-warning
 * ceremony needed beyond update()'s own rename-swap rollback. The
 * path-traversal and size/entry-count safety checks mirror
 * UpdatePackageValidator's (smaller limits here — a theme is not a whole
 * application), but the *content* requirements are theme-specific: a
 * style.css with a non-empty "Theme Name:" header, rather than a
 * version.php manifest — read directly out of the archive before
 * extracting, so a missing header is rejected without ever touching the
 * filesystem. update()'s staged-extract-then-rename-swap approach mirrors
 * Lumora Gallery's ThemeService::updateFromZip() (LG-043) — reference
 * material only; this project introduces no dependency on that one.
 */
final class ThemeInstaller
{
    private const MAX_ENTRIES = 2000;

    private const MAX_UNCOMPRESSED_SIZE = 50 * 1024 * 1024;

    public function __construct(
        private readonly string $themesPath,
        private readonly ThemeRegistry $themes,
    ) {
    }

    /**
     * Removes an installed theme's directory entirely (LP-044's "Delete
     * inactive themes" action). Whether the theme is currently active is
     * the caller's concern — this class only knows about the filesystem,
     * not which theme is active — so callers must check ThemeInfo::$isActive
     * themselves before calling this.
     */
    public function delete(string $slug): void
    {
        $destination = rtrim($this->themesPath, '/') . '/' . $slug;

        if ($slug === '' || !is_dir($destination)) {
            throw new RuntimeException('That theme could not be found.');
        }

        $this->removeDirectory($destination);
    }

    public function install(string $zipPath): ThemeInfo
    {
        $zip = $this->openZip($zipPath);

        try {
            [$rootPrefix, $themeName] = $this->validateArchive($zip);

            $slug = $this->deriveSlug($themeName, $rootPrefix);
            $destination = rtrim($this->themesPath, '/') . '/' . $slug;

            if (is_dir($destination)) {
                throw new RuntimeException("A theme named \"{$slug}\" is already installed. Remove it first or rename the archive, or use Update instead to replace its files in place.");
            }

            $this->extractAndFinalize($zip, $rootPrefix, $destination);

            $info = $this->themes->infoFor($slug);

            if ($info === null) {
                throw new RuntimeException('The theme was installed but could not be read back.');
            }

            return $info;
        } finally {
            $zip->close();
        }
    }

    /**
     * Replaces an already-installed theme's files with the contents of a
     * new ZIP, in place — LP-081's counterpart to install(), which always
     * hard-rejects an existing destination. The archive is extracted to a
     * staging directory and validated exactly as install() validates one
     * (same style.css + "Theme Name:" header requirement — the header's
     * declared name does not need to match $slug; the slug the site
     * already knows this theme by always wins, since that's what every
     * active_theme option/nav menu/widget assignment references), then
     * swapped into place via two fast rename() calls: the live directory
     * is displaced first, the staged one takes its place second, and only
     * then is the displaced original removed. If the second rename fails,
     * the displaced original is renamed straight back — the window where
     * $destination doesn't exist at all is as small as the filesystem
     * allows, and a failure never leaves the theme half-installed.
     */
    public function update(string $zipPath, string $slug): ThemeInfo
    {
        $destination = rtrim($this->themesPath, '/') . '/' . $slug;

        if ($slug === '' || !is_dir($destination)) {
            throw new RuntimeException('That theme could not be found.');
        }

        $zip = $this->openZip($zipPath);

        try {
            [$rootPrefix] = $this->validateArchive($zip);

            $staging = $destination . '.updating-' . bin2hex(random_bytes(4));

            if (!mkdir($staging, 0755, true)) {
                throw new RuntimeException('Unable to create a staging directory for the update.');
            }

            if (!$zip->extractTo($staging)) {
                $this->removeDirectory($staging);

                throw new RuntimeException('Failed to extract the theme archive.');
            }

            $extractedRoot = rtrim($staging . '/' . $rootPrefix, '/');

            $displaced = $destination . '.replaced-' . bin2hex(random_bytes(4));

            if (!rename($destination, $displaced)) {
                $this->removeDirectory($staging);

                throw new RuntimeException('Could not remove the existing theme files before updating.');
            }

            if (!rename($extractedRoot, $destination)) {
                rename($displaced, $destination);
                $this->removeDirectory($staging);

                throw new RuntimeException('Could not install the updated theme files; the previous version was restored.');
            }

            $this->removeDirectory($displaced);
            $this->removeDirectory($staging);

            $info = $this->themes->infoFor($slug);

            if ($info === null) {
                throw new RuntimeException('The theme was updated but could not be read back.');
            }

            return $info;
        } finally {
            $zip->close();
        }
    }

    private function openZip(string $zipPath): ZipArchive
    {
        if (!is_file($zipPath)) {
            throw new RuntimeException('The uploaded file could not be found.');
        }

        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The uploaded file is not a valid ZIP archive.');
        }

        return $zip;
    }

    /**
     * Validates entry count, path safety, and total uncompressed size, then
     * confirms a style.css with a "Theme Name:" header exists — the shared
     * contract both install() and update() require before touching the
     * filesystem. Does not extract anything.
     *
     * @return array{0: string, 1: string} [$rootPrefix, $themeName]
     */
    private function validateArchive(ZipArchive $zip): array
    {
        $numFiles = $zip->numFiles;

        if ($numFiles === 0) {
            throw new RuntimeException('The uploaded archive is empty.');
        }

        if ($numFiles > self::MAX_ENTRIES) {
            throw new RuntimeException('The archive contains too many files to be a valid theme package.');
        }

        $names = [];
        $totalUncompressed = 0;

        for ($i = 0; $i < $numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $name = (string) $stat['name'];

            if ($this->isUnsafeEntryName($name)) {
                throw new RuntimeException('The archive contains an unsafe file path and was rejected.');
            }

            $names[] = $name;
            $totalUncompressed += (int) $stat['size'];
        }

        if ($totalUncompressed > self::MAX_UNCOMPRESSED_SIZE) {
            throw new RuntimeException('The archive is too large to be processed safely.');
        }

        $rootPrefix = $this->detectRootPrefix($names);
        $styleCssEntry = $rootPrefix . 'style.css';

        if (!in_array($styleCssEntry, $names, true)) {
            throw new RuntimeException('The archive does not contain a style.css file and cannot be a valid theme.');
        }

        $styleCssContents = $zip->getFromName($styleCssEntry);

        if ($styleCssContents === false) {
            throw new RuntimeException('Unable to read style.css from the archive.');
        }

        $themeName = $this->parseThemeName($styleCssContents);

        if ($themeName === '') {
            throw new RuntimeException('The archive\'s style.css does not have a valid "Theme Name:" header.');
        }

        return [$rootPrefix, $themeName];
    }

    private function extractAndFinalize(ZipArchive $zip, string $rootPrefix, string $destination): void
    {
        $tempDestination = $destination . '.installing-' . bin2hex(random_bytes(4));

        if (!mkdir($tempDestination, 0755, true)) {
            throw new RuntimeException('Unable to create the theme directory.');
        }

        if (!$zip->extractTo($tempDestination)) {
            $this->removeDirectory($tempDestination);

            throw new RuntimeException('Failed to extract the theme archive.');
        }

        $extractedRoot = rtrim($tempDestination . '/' . $rootPrefix, '/');

        if ($rootPrefix !== '') {
            // The theme's files live one level down (e.g. a GitHub export
            // wrapping everything in "ThemeName-1.0.0/") — move them up to
            // the real destination directly rather than leaving an extra
            // nested folder inside the installed theme.
            $moved = rename($extractedRoot, $destination);
            $this->removeDirectory($tempDestination);
        } else {
            $moved = rename($tempDestination, $destination);
        }

        if (!$moved) {
            $this->removeDirectory($tempDestination);
            $this->removeDirectory($destination);

            throw new RuntimeException('Failed to finalize the theme installation.');
        }
    }

    private function parseThemeName(string $styleCssContents): string
    {
        if (preg_match('/^\s*Theme Name:\s*(.+)$/mi', $styleCssContents, $matches) === 1) {
            return trim($matches[1]);
        }

        return '';
    }

    private function isUnsafeEntryName(string $name): bool
    {
        if ($name === '') {
            return true;
        }

        if (str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            return true;
        }

        if (preg_match('#^[A-Za-z]:#', $name) === 1) {
            return true;
        }

        return str_contains($name, '..');
    }

    /**
     * Detects a single wrapping top-level directory, e.g. a GitHub
     * export's "ThemeName-1.0.0/" folder, mirroring
     * UpdatePackageValidator::detectRootPrefix().
     *
     * @param array<int, string> $names
     */
    private function detectRootPrefix(array $names): string
    {
        if (in_array('style.css', $names, true)) {
            return '';
        }

        foreach ($names as $name) {
            if (str_ends_with($name, '/style.css') && substr_count($name, '/') === 1) {
                return substr($name, 0, -strlen('style.css'));
            }
        }

        return '';
    }

    private function deriveSlug(string $themeName, string $rootPrefix): string
    {
        $slug = $this->slugify($themeName);

        if ($slug !== '') {
            return $slug;
        }

        $fromPrefix = $this->slugify(rtrim($rootPrefix, '/'));

        return $fromPrefix !== '' ? $fromPrefix : 'theme-' . bin2hex(random_bytes(4));
    }

    private function slugify(string $value): string
    {
        // Lowercase first — the character class below only keeps a-z0-9,
        // so applying it before lowercasing would treat every uppercase
        // letter as a separator and shred the name (e.g. "My Cool Theme"
        // would become "y-ool-heme" instead of "my-cool-theme").
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;

            if (is_dir($itemPath) && !is_link($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }

        rmdir($path);
    }
}
