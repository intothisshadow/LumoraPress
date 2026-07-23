<?php

declare(strict_types=1);

use LumoraPress\Core\Menus\Menus;

/**
 * Procedural navigation menu API for themes.
 */

if (!function_exists('register_nav_menu')) {
    function register_nav_menu(string $location, string $label): void
    {
        Menus::instance()->registerLocation($location, $label);
    }
}

if (!function_exists('has_nav_menu')) {
    function has_nav_menu(string $location): bool
    {
        return Menus::instance()->hasItems($location);
    }
}

if (!function_exists('nav_menu')) {
    function nav_menu(string $location, string $menuClass = 'lp-nav-menu'): void
    {
        $items = Menus::instance()->items($location);

        if ($items === []) {
            return;
        }

        echo '<ul class="' . esc_attr($menuClass) . '">';

        foreach ($items as $item) {
            $target = $item['target'] === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '';
            echo '<li class="lp-nav-menu__item">'
                . '<a href="' . esc_url($item['url']) . '"' . $target . '>' . esc_html($item['label']) . '</a>'
                . '</li>';
        }

        echo '</ul>';
    }
}
