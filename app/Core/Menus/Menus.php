<?php

/**
 * Static bridge exposing the single MenuManager instance to the procedural register_nav_menu()/nav_menu() helpers.
 *
 * @package LumoraPress
 * @subpackage Menus
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Menus;

/**
 * Static bridge exposing the single MenuManager instance to the
 * procedural register_nav_menu()/nav_menu() helpers.
 */
final class Menus
{
    private static ?MenuManager $instance = null;

    public static function set(MenuManager $manager): void
    {
        self::$instance = $manager;
    }

    public static function instance(): MenuManager
    {
        if (self::$instance === null) {
            self::$instance = new MenuManager();
        }

        return self::$instance;
    }
}
