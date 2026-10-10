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

if (!function_exists('the_homepage_widgets')) {
    /**
     * Prints the "homepage" widget area above the post list, on the first page of the genuine
     * homepage only (not the Posts page, archives, or page 2 onward). Like the sidebar and footer
     * areas it is optional: with no widgets assigned, or in a theme that never registered the
     * area, it prints nothing, not even an empty wrapper.
     *
     * @param array<string, mixed> $pagination The listing's pagination array, as index.php receives it.
     */
    function the_homepage_widgets(?string $pageTitle, array $pagination = []): void
    {
        if ($pageTitle !== null || (int) ($pagination['page'] ?? 1) > 1 || !is_active_sidebar('homepage')) {
            return;
        }

        echo '<section class="lp-homepage-widgets" aria-label="Homepage widgets">';
        dynamic_sidebar('homepage');
        echo '</section>', "\n";
    }
}
