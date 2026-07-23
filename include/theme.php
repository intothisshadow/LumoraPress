<?php

declare(strict_types=1);

use LumoraPress\Core\Theme\ActiveTheme;

/**
 * Procedural template helpers available inside theme template files.
 */

if (!function_exists('get_header')) {
    function get_header(): void
    {
        do_action('get_header');
        ActiveTheme::instance()->renderPartial('header');
    }
}

if (!function_exists('get_footer')) {
    function get_footer(): void
    {
        do_action('get_footer');
        ActiveTheme::instance()->renderPartial('footer');
    }
}

if (!function_exists('get_sidebar')) {
    function get_sidebar(): void
    {
        do_action('get_sidebar');
        ActiveTheme::instance()->renderPartial('sidebar');
    }
}

if (!function_exists('theme_url')) {
    function theme_url(string $path = ''): string
    {
        return ActiveTheme::instance()->themeUrl($path);
    }
}
