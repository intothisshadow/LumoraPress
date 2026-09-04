<?php

/**
 * Procedural widget API for themes (register_sidebar(), dynamic_sidebar()) and plugins (register_widget()).
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

use LumoraPress\Core\Widgets\Widgets;

if (!function_exists('register_sidebar')) {
    function register_sidebar(string $id, string $name, string $description = ''): void
    {
        Widgets::instance()->registerSidebar($id, $name, $description);
    }
}

if (!function_exists('register_widget')) {
    function register_widget(string $type, string $label, callable $render): void
    {
        Widgets::instance()->registerWidget($type, $label, $render);
    }
}

if (!function_exists('dynamic_sidebar')) {
    function dynamic_sidebar(string $sidebarId): void
    {
        Widgets::instance()->renderSidebar($sidebarId);
    }
}

if (!function_exists('is_active_sidebar')) {
    function is_active_sidebar(string $sidebarId): bool
    {
        return Widgets::instance()->hasWidgets($sidebarId);
    }
}
