<?php

/**
 * Static bridge exposing the active ThemeRenderer to the procedural get_header()/get_footer()/get_sidebar() template helpers.
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

use RuntimeException;

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
