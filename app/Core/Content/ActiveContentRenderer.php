<?php

declare(strict_types=1);

namespace LumoraPress\Core\Content;

use LumoraPress\Services\ContentRenderer;
use RuntimeException;

/**
 * Static bridge exposing the bootstrapped ContentRenderer to theme
 * templates' procedural render_content() helper — mirrors ActiveTheme/
 * SiteBranding/FeaturedImages/ThemePreview: themes are plain procedural
 * PHP files with no route to Kernel-wired services of their own.
 */
final class ActiveContentRenderer
{
    private static ?ContentRenderer $instance = null;

    public static function set(ContentRenderer $renderer): void
    {
        self::$instance = $renderer;
    }

    public static function instance(): ContentRenderer
    {
        if (self::$instance === null) {
            throw new RuntimeException('Content renderer has not been initialized.');
        }

        return self::$instance;
    }
}
