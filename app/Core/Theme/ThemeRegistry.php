<?php

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

/**
 * Discovers installed themes under content/themes (LP-034), one directory
 * per theme — same "directory = installable unit" shape as
 * PluginManager::discover(), just with metadata parsing on top since a
 * theme (unlike a plugin) has a whole Appearance UI built around it.
 *
 * A theme with no parseable "Theme Name:" header still gets listed (with
 * its slug title-cased as a fallback name) rather than silently excluded
 * — a missing/malformed style.css header should never make an installed
 * theme invisible or unselectable.
 */
final class ThemeRegistry
{
    private const SCREENSHOT_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

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

    private function buildInfo(string $slug, string $dir): ThemeInfo
    {
        $header = $this->parseHeader($dir . '/style.css');

        return new ThemeInfo(
            slug: $slug,
            name: $header['name'] !== '' ? $header['name'] : $this->titleCaseSlug($slug),
            description: $header['description'],
            version: $header['version'],
            author: $header['author'],
            screenshotUrl: $this->findScreenshotUrl($slug, $dir),
            isActive: $slug === $this->activeTheme,
        );
    }

    /**
     * @return array{name: string, description: string, version: string, author: string}
     */
    private function parseHeader(string $styleCssPath): array
    {
        $result = ['name' => '', 'description' => '', 'version' => '', 'author' => ''];

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

        foreach (['name' => 'Theme Name', 'description' => 'Description', 'version' => 'Version', 'author' => 'Author'] as $key => $label) {
            if (preg_match('/^\s*' . preg_quote($label, '/') . ':\s*(.+)$/mi', $head, $matches) === 1) {
                $result[$key] = trim($matches[1]);
            }
        }

        return $result;
    }

    private function findScreenshotUrl(string $slug, string $dir): ?string
    {
        foreach (self::SCREENSHOT_EXTENSIONS as $extension) {
            if (is_file($dir . '/screenshot.' . $extension)) {
                return rtrim($this->themesUrl, '/') . '/' . $slug . '/screenshot.' . $extension;
            }
        }

        return null;
    }

    private function titleCaseSlug(string $slug): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }
}
