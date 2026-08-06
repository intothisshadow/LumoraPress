<?php

/**
 * Discovers installed plugins under content/plugins (LP-045) and parses their metadata for the Plugin Browser.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Plugin;

/**
 * Discovers installed plugins under content/plugins (LP-045), one
 * directory per plugin — same "directory = installable unit" shape
 * PluginManager::discover() already uses for loading, just with metadata
 * parsing on top since the Plugin Browser needs a details panel before
 * activation. Deliberately separate from PluginManager: that class only
 * cares about *loading* active plugins (require_once on the main file),
 * this one only cares about *describing* every installed plugin,
 * active or not — mirrors the ThemeRegistry/ThemeRenderer split (LP-034/
 * LP-044).
 *
 * A plugin with no parseable "Plugin Name:" header still gets listed
 * (with its slug title-cased as a fallback name) rather than silently
 * excluded — a missing/malformed header should never make an installed
 * plugin invisible or unselectable.
 */
final class PluginRegistry
{
    private const SCREENSHOT_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'avif'];

    /**
     * Priority order for the primary card image when more than one
     * preview basename is present — matches ThemeRegistry's convention.
     */
    private const PREVIEW_BASENAMES = ['preview', 'thumbnail', 'screenshot'];

    private const README_FILENAMES = ['README.md', 'readme.md', 'Readme.md', 'README.txt', 'readme.txt', 'README'];

    private const CHANGELOG_FILENAMES = ['CHANGELOG.md', 'changelog.md', 'Changelog.md', 'CHANGELOG.txt', 'changelog.txt'];

    private const MAX_DOCUMENT_BYTES = 262144;

    /**
     * @param array<int, string> $activePlugins
     */
    public function __construct(
        private readonly string $pluginsPath,
        private readonly string $pluginsUrl,
        private readonly array $activePlugins,
    ) {
    }

    /**
     * @return array<int, PluginInfo>
     */
    public function discover(): array
    {
        $dirs = glob(rtrim($this->pluginsPath, '/') . '/*', GLOB_ONLYDIR) ?: [];
        $plugins = array_map(fn (string $dir): PluginInfo => $this->buildInfo(basename($dir), $dir), $dirs);

        usort($plugins, static fn (PluginInfo $a, PluginInfo $b): int => strcasecmp($a->name, $b->name));

        return $plugins;
    }

    /**
     * Builds metadata for a single plugin by slug, without discovering
     * the rest — used by PluginInstaller to describe a plugin
     * immediately after extracting (or before extracting, from an
     * already-open archive's main-file contents).
     */
    public function infoFor(string $slug): ?PluginInfo
    {
        $dir = rtrim($this->pluginsPath, '/') . '/' . $slug;

        if (!is_dir($dir)) {
            return null;
        }

        return $this->buildInfo($slug, $dir);
    }

    /**
     * Reads a README/CHANGELOG file's contents for the details panel.
     * $filename must be the exact value returned on that plugin's
     * PluginInfo (readmeFile/changelogFile) — those are only ever set to
     * filenames this class itself found on disk, so there is no
     * attacker-controlled path here even though the caller supplies it.
     */
    public function documentContent(string $slug, string $filename): ?string
    {
        $path = rtrim($this->pluginsPath, '/') . '/' . $slug . '/' . $filename;

        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path, false, null, 0, self::MAX_DOCUMENT_BYTES);

        return $contents === false ? null : $contents;
    }

    private function buildInfo(string $slug, string $dir): PluginInfo
    {
        $header = $this->parseHeader($dir . '/' . $slug . '.php');
        $screenshots = $this->findScreenshots($slug, $dir);
        $tags = $header['tags'] === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $header['tags']))));
        $requiresPlugins = $header['requiresPlugins'] === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $header['requiresPlugins']))));

        $isDisabled = $header['requiresPhp'] !== '' && version_compare(PHP_VERSION, $header['requiresPhp'], '<');

        return new PluginInfo(
            slug: $slug,
            name: $header['name'] !== '' ? $header['name'] : $this->titleCaseSlug($slug),
            description: $header['description'],
            version: $header['version'],
            author: $header['author'],
            authorUri: $header['authorUri'],
            pluginUri: $header['pluginUri'],
            license: $header['license'],
            licenseUri: $header['licenseUri'],
            requiresAtLeast: $header['requiresAtLeast'],
            requiresPhp: $header['requiresPhp'],
            requiresPlugins: $requiresPlugins,
            tags: $tags,
            screenshots: $screenshots,
            screenshotUrl: $screenshots[0] ?? null,
            relativePath: 'content/plugins/' . $slug,
            readmeFile: $this->findDocument($dir, self::README_FILENAMES),
            changelogFile: $this->findDocument($dir, self::CHANGELOG_FILENAMES),
            isActive: in_array($slug, $this->activePlugins, true),
            isDisabled: $isDisabled,
        );
    }

    /**
     * @return array{
     *     name: string, description: string, version: string, author: string,
     *     authorUri: string, pluginUri: string, license: string, licenseUri: string,
     *     requiresAtLeast: string, requiresPhp: string, requiresPlugins: string, tags: string
     * }
     */
    private function parseHeader(string $mainFilePath): array
    {
        $result = [
            'name' => '', 'description' => '', 'version' => '', 'author' => '',
            'authorUri' => '', 'pluginUri' => '', 'license' => '', 'licenseUri' => '',
            'requiresAtLeast' => '', 'requiresPhp' => '', 'requiresPlugins' => '', 'tags' => '',
        ];

        if (!is_file($mainFilePath)) {
            return $result;
        }

        // Only the header comment block matters — reading the whole
        // file just to find a handful of "Key: value" lines near the
        // top would be wasteful for a large plugin.
        $head = file_get_contents($mainFilePath, false, null, 0, 8192);

        if ($head === false) {
            return $result;
        }

        $labels = [
            'name' => 'Plugin Name',
            'description' => 'Description',
            'version' => 'Version',
            'author' => 'Author',
            'authorUri' => 'Author URI',
            'pluginUri' => 'Plugin URI',
            'license' => 'License',
            'licenseUri' => 'License URI',
            'requiresAtLeast' => 'Requires at least',
            'requiresPhp' => 'Requires PHP',
            'requiresPlugins' => 'Requires Plugins',
            'tags' => 'Tags',
        ];

        foreach ($labels as $key => $label) {
            if (preg_match('/^[\s*\/]*' . preg_quote($label, '/') . ':\s*(.+)$/mi', $head, $matches) === 1) {
                $result[$key] = trim($matches[1]);
            }
        }

        return $result;
    }

    /**
     * Finds every preview image in the plugin directory named
     * preview.*, thumbnail.*, or screenshot.* (LP-045), plus numbered
     * variants (screenshot-2.png, screenshot-3.jpg, ...) so a plugin can
     * provide multiple screenshots for the details panel gallery. The
     * first entry (by basename priority, then ascending number) is used
     * as the card thumbnail. Mirrors ThemeRegistry::findScreenshots()
     * exactly.
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

            $matches[$sortKey] = rtrim($this->pluginsUrl, '/') . '/' . $slug . '/' . $entry;
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
