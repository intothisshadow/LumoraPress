<?php

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

use RuntimeException;

/**
 * Static bridge exposing the active ThemeRenderer to the procedural
 * get_header()/get_footer()/get_sidebar() template helpers.
 */
final class ActiveTheme
{
    private static ?ThemeRenderer $instance = null;

    public static function set(ThemeRenderer $renderer): void
    {
        self::$instance = $renderer;
    }

    public static function instance(): ThemeRenderer
    {
        if (self::$instance === null) {
            throw new RuntimeException('Theme renderer has not been initialized.');
        }

        return self::$instance;
    }
}
