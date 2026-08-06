<?php

/**
 * Metadata for one installed theme, as discovered by ThemeRegistry from its style.css comment header.
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
 * Metadata for one installed theme, as discovered by ThemeRegistry —
 * parsed from its style.css comment header (the classic WordPress
 * "Theme Name: / Description: / Version: / Author: / Theme URI: /
 * Author URI: / License: / License URI: / Tags: / Requires at least: /
 * Requires PHP:" convention) plus any preview images and documentation
 * files present in the theme directory (LP-044).
 */
final class ThemeInfo
{
    /**
     * @param array<int, string> $tags
     * @param array<int, string> $screenshots
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description,
        public readonly string $version,
        public readonly string $author,
        public readonly string $authorUri,
        public readonly string $themeUri,
        public readonly string $license,
        public readonly string $licenseUri,
        public readonly string $requiresAtLeast,
        public readonly string $requiresPhp,
        public readonly array $tags,
        public readonly array $screenshots,
        public readonly ?string $screenshotUrl,
        public readonly string $relativePath,
        public readonly ?string $readmeFile,
        public readonly ?string $changelogFile,
        public readonly bool $isActive,
    ) {
    }
}
