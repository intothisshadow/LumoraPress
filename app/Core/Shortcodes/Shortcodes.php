<?php

/**
 * Static bridge exposing the single ShortcodeManager instance to the procedural register_shortcode() helper.
 *
 * @package LumoraPress
 * @subpackage Shortcodes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Shortcodes;

/**
 * Static bridge exposing the single ShortcodeManager instance to the
 * procedural register_shortcode() helper, mirroring Hooks/Widgets' own
 * identical bridge classes.
 */
final class Shortcodes
{
    private static ?ShortcodeManager $instance = null;

    public static function set(ShortcodeManager $manager): void
    {
        self::$instance = $manager;
    }

    public static function instance(): ShortcodeManager
    {
        if (self::$instance === null) {
            self::$instance = new ShortcodeManager();
        }

        return self::$instance;
    }
}
