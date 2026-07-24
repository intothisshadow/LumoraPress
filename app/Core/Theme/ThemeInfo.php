<?php

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

/**
 * Metadata for one installed theme, as discovered by ThemeRegistry —
 * parsed from its style.css comment header (the classic WordPress
 * "Theme Name: / Description: / Version: / Author:" convention) plus a
 * screenshot file, if present.
 */
final class ThemeInfo
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description,
        public readonly string $version,
        public readonly string $author,
        public readonly ?string $screenshotUrl,
        public readonly bool $isActive,
    ) {
    }
}
