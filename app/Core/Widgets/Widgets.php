<?php

declare(strict_types=1);

namespace LumoraPress\Core\Widgets;

/**
 * Static bridge exposing the single WidgetManager instance to the
 * procedural register_sidebar()/register_widget()/dynamic_sidebar() helpers.
 */
final class Widgets
{
    private static ?WidgetManager $instance = null;

    public static function set(WidgetManager $manager): void
    {
        self::$instance = $manager;
    }

    public static function instance(): WidgetManager
    {
        if (self::$instance === null) {
            self::$instance = new WidgetManager();
        }

        return self::$instance;
    }
}
