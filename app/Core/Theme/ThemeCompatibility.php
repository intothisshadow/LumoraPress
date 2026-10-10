<?php

/**
 * Decides whether a theme can run on this site: the Lumora Press and PHP versions it asks for, and the template files it must ship.
 *
 * @package LumoraPress
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.23.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

/**
 * Returns plain-language problems rather than a yes/no so the Themes screen, the activation
 * check, and the ZIP installer can all show the same reasons. A version string that doesn't look
 * like a version is ignored, so a typo in a theme's header never locks the theme out.
 */
final class ThemeCompatibility
{
    /**
     * The templates ThemeRenderer asks for and throws without; sidebar.php, comments.php and
     * functions.php are optional.
     */
    public const REQUIRED_TEMPLATES = [
        'header.php', 'footer.php', 'index.php', 'single.php', 'page.php',
        'archive.php', 'search.php', '404.php', 'maintenance.php',
    ];

    public function __construct(
        private readonly string $themesPath,
        private readonly string $coreVersion,
        private readonly string $phpVersion = PHP_VERSION,
    ) {
    }

    /**
     * @param array<int, string> $presentFiles File names at the top level of the theme.
     * @return array<int, string>
     */
    public function problems(string $requiresAtLeast, string $requiresPhp, array $presentFiles): array
    {
        $problems = [];
        $requiresAtLeast = trim($requiresAtLeast);
        $requiresPhp = trim($requiresPhp);

        if ($this->isVersion($requiresAtLeast) && version_compare($this->coreVersion, $requiresAtLeast, '<')) {
            $problems[] = sprintf('It needs Lumora Press %s or newer; this site is running %s.', $requiresAtLeast, $this->coreVersion);
        }

        if ($this->isVersion($requiresPhp) && version_compare($this->phpVersion, $requiresPhp, '<')) {
            $problems[] = sprintf('It needs PHP %s or newer; this server is running PHP %s.', $requiresPhp, $this->phpVersion);
        }

        $missing = array_values(array_diff(self::REQUIRED_TEMPLATES, $presentFiles));

        if ($missing !== []) {
            $problems[] = 'It is missing required template files: ' . implode(', ', $missing) . '.';
        }

        return $problems;
    }

    /**
     * @return array<int, string>
     */
    public function problemsFor(ThemeInfo $theme): array
    {
        $directory = rtrim($this->themesPath, '/') . '/' . $theme->slug;
        $files = is_dir($directory) ? (scandir($directory) ?: []) : [];

        return $this->problems($theme->requiresAtLeast, $theme->requiresPhp, $files);
    }

    private function isVersion(string $value): bool
    {
        return preg_match('/^\d+(\.\d+)*$/', $value) === 1;
    }
}
