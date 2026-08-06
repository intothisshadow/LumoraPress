<?php

/**
 * Static bridge exposing the single WidgetManager instance to the procedural register_sidebar()/register_widget()/dynamic_sidebar() helpers.
 *
 * @package LumoraPress
 * @subpackage Widgets
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

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
