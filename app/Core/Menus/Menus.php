<?php

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
