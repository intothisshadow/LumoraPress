<?php

/**
 * A user's chosen admin color scheme (Light, Dark, or follow the OS/browser setting).
 *
 * @package LumoraPress
 * @subpackage Models
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Models;

/**
 * Unlike ContentFormat, there is no site-wide theme setting to defer to, so
 * `Auto` (follow the OS/browser's prefers-color-scheme) is a real case here
 * rather than being represented by null — see User::$themePreference.
 */
enum ThemePreference: string
{
    case Light = 'light';
    case Dark = 'dark';
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Light => 'Light',
            self::Dark => 'Dark',
            self::Auto => 'Follow System',
        };
    }
}
