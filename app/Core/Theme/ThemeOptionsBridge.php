<?php

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

use RuntimeException;

/**
 * Static bridge exposing the request's ThemeOptions instance to the
 * procedural theme_option()/theme_options_css() template helpers
 * (include/helpers.php) — the same "set once in bootstrap, read via a
 * static class" pattern SiteBranding and ActiveTheme already use, since
 * theme templates have no direct route to the Kernel.
 */
final class ThemeOptionsBridge
{
    private static ?ThemeOptions $instance = null;

    public static function set(ThemeOptions $options): void
    {
        self::$instance = $options;
    }

    public static function instance(): ThemeOptions
    {
        if (self::$instance === null) {
            throw new RuntimeException('Theme options have not been initialized.');
        }

        return self::$instance;
    }
}
