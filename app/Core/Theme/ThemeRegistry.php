<?php

/**
 * Discovers installed themes under content/themes (LP-034) and parses their metadata.
 *
 * @package LumoraPress
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

/**
 * Discovers installed themes under content/themes, one directory per theme,
 * and parses each style.css header plus preview images and README/CHANGELOG
 * files for the Appearance page's details panel.
 *
 * A theme with no parseable "Theme Name:" header still gets listed, with
 * its slug title-cased as a fallback name, rather than silently excluded.
 */
final class ThemeRegistry
{
    private const SCREENSHOT_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'avif'];

    /**
     * Priority order for the primary card image when more than one
     * preview basename is present.
     */
    private const PREVIEW_BASENAMES = ['preview', 'thumbnail', 'screenshot'];

    private const README_FILENAMES = ['README.md', 'readme.md', 'Readme.md', 'README.txt', 'readme.txt', 'README'];

    private const CHANGELOG_FILENAMES = ['CHANGELOG.md', 'changelog.md', 'Changelog.md', 'CHANGELOG.txt', 'changelog.txt'];

    private const MAX_DOCUMENT_BYTES = 262144;

    public function __construct(
        private readonly string $themesPath,
        private readonly string $themesUrl,
        private readonly string $activeTheme,
    ) {
    }

    /**
     * @return array<int, ThemeInfo>
     */
    public function discover(): array
    {
        $dirs = glob(rtrim($this->themesPath, '/') . '/*', GLOB_ONLYDIR) ?: [];
        $themes = array_map(fn (string $dir): ThemeInfo => $this->buildInfo(basename($dir), $dir), $dirs);

        usort($themes, static fn (ThemeInfo $a, ThemeInfo $b): int => strcasecmp($a->name, $b->name));

        return $themes;
    }

    /**
     * Builds metadata for a single theme by slug, without discovering the
     * rest — used by ThemeInstaller to describe a theme immediately after
     * extracting it.
     */
    public function infoFor(string $slug): ?ThemeInfo
    {
        $dir = rtrim($this->themesPath, '/') . '/' . $slug;

        if (!is_dir($dir)) {
            return null;
        }

        return $this->buildInfo($slug, $dir);
    }

    /**
     * Reads a README/CHANGELOG file's contents for the details panel.
     * $filename must be the exact value returned on that theme's
     * ThemeInfo (readmeFile/changelogFile) — those are only ever set to
     * filenames this class itself found on disk, so there is no
     * attacker-controlled path here even though the caller supplies it.
     */
    public function documentContent(string $slug, string $filename): ?string
    {
        $path = rtrim($this->themesPath, '/') . '/' . $slug . '/' . $filename;

        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path, false, null, 0, self::MAX_DOCUMENT_BYTES);

        return $contents === false ? null : $contents;
    }

    private function buildInfo(string $slug, string $dir): ThemeInfo
    {
        $header = $this->parseHeader($dir . '/style.css');
        $screenshots = $this->findScreenshots($slug, $dir);
        $tags = $header['tags'] === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $header['tags']))));

        return new ThemeInfo(
            slug: $slug,
            name: $header['name'] !== '' ? $header['name'] : $this->titleCaseSlug($slug),
            description: $header['description'],
            version: $header['version'],
            author: $header['author'],
            authorUri: $header['authorUri'],
            themeUri: $header['themeUri'],
            license: $header['license'],
            licenseUri: $header['licenseUri'],
            requiresAtLeast: $header['requiresAtLeast'],
            requiresPhp: $header['requiresPhp'],
            tags: $tags,
            screenshots: $screenshots,
            screenshotUrl: $screenshots[0] ?? null,
            relativePath: 'content/themes/' . $slug,
            readmeFile: $this->findDocument($dir, self::README_FILENAMES),
            changelogFile: $this->findDocument($dir, self::CHANGELOG_FILENAMES),
            isActive: $slug === $this->activeTheme,
        );
    }

    /**
     * @return array{
     *     name: string, description: string, version: string, author: string,
     *     authorUri: string, themeUri: string, license: string, licenseUri: string,
     *     requiresAtLeast: string, requiresPhp: string, tags: string
     * }
     */
    private function parseHeader(string $styleCssPath): array
    {
        $result = [
            'name' => '', 'description' => '', 'version' => '', 'author' => '',
            'authorUri' => '', 'themeUri' => '', 'license' => '', 'licenseUri' => '',
            'requiresAtLeast' => '', 'requiresPhp' => '', 'tags' => '',
        ];

        if (!is_file($styleCssPath)) {
            return $result;
        }

        // Only the header comment block matters — reading the whole file
        // just to find a handful of "Key: value" lines near the top would
        // be wasteful for a large stylesheet.
        $head = file_get_contents($styleCssPath, false, null, 0, 8192);

        if ($head === false) {
            return $result;
        }

        $labels = [
            'name' => 'Theme Name',
            'description' => 'Description',
            'version' => 'Version',
            'author' => 'Author',
            'authorUri' => 'Author URI',
            'themeUri' => 'Theme URI',
            'license' => 'License',
            'licenseUri' => 'License URI',
            'requiresAtLeast' => 'Requires at least',
            'requiresPhp' => 'Requires PHP',
            'tags' => 'Tags',
        ];

        foreach ($labels as $key => $label) {
            if (preg_match('/^\s*' . preg_quote($label, '/') . ':\s*(.+)$/mi', $head, $matches) === 1) {
                $result[$key] = trim($matches[1]);
            }
        }

        return $result;
    }

    /**
     * Finds every preview image named preview.*, thumbnail.*, or
     * screenshot.*, plus numbered variants (screenshot-2.png, ...). The
     * first entry, by basename priority then ascending number, is the
     * card thumbnail.
     *
     * @return array<int, string>
     */
    private function findScreenshots(string $slug, string $dir): array
    {
        $entries = scandir($dir) ?: [];
        $extensionPattern = implode('|', array_map(static fn (string $ext): string => preg_quote($ext, '/'), self::SCREENSHOT_EXTENSIONS));
        $pattern = '/^(' . implode('|', self::PREVIEW_BASENAMES) . ')(?:-(\d+))?\.(' . $extensionPattern . ')$/i';

        $matches = [];

        foreach ($entries as $entry) {
            if (preg_match($pattern, $entry, $groups) !== 1) {
                continue;
            }

            $priority = array_search(strtolower($groups[1]), self::PREVIEW_BASENAMES, true);
            $number = isset($groups[2]) && $groups[2] !== '' ? (int) $groups[2] : 0;
            $sortKey = sprintf('%d-%05d-%s', $priority, $number, strtolower($entry));

            $matches[$sortKey] = rtrim($this->themesUrl, '/') . '/' . $slug . '/' . $entry;
        }

        ksort($matches);

        return array_values($matches);
    }

    /**
     * @param array<int, string> $candidates
     */
    private function findDocument(string $dir, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_file($dir . '/' . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function titleCaseSlug(string $slug): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }
}
