<?php

/**
 * Lumora Classic setup: registers widget areas and navigation menu locations.
 *
 * @package LumoraPress
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

/**
 * Core widget types (Text, Custom HTML, Search, Navigation Menu, Pages,
 * Categories, Recent Posts, Recent Comments, Archives, Tag Cloud, Meta)
 * are registered by LumoraPress\Core\Widgets\CoreWidgets, not here.
 */

register_sidebar('primary', 'Primary Sidebar', 'Appears alongside posts and pages.');
register_sidebar('footer', 'Footer Widget Area', 'Appears in the site footer, above the footer navigation.');
register_sidebar('homepage', 'Homepage Widget Area', 'Appears above the post list on the first page of the homepage only. Leave it empty to show nothing.');

register_nav_menu('primary', 'Primary Menu');
register_nav_menu('footer', 'Footer Menu');

// The Customize options this theme implements; any it doesn't list stay hidden.
add_theme_support(
    'sidebar-position',
    'footer-columns',
    'image-styling',
    'back-to-top',
    'post-details',
    'tagline',
    'page-titles',
    'heading-font',
    'dark-mode',
    'search-bar',
);
register_nav_menu('social', 'Social Links Menu');
register_nav_menu('secondary', 'Secondary Menu');

// Read by header.php through the_font_stylesheets(), and by Settings > Performance when
// offering fonts for local hosting.
add_filter('font_stylesheet_urls', static function (array $urls): array {
    if (theme_option('lumora_classic_load_fonts') !== '0') {
        $urls[] = 'https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400..700;1,400..700&family=Playfair+Display:wght@500..800&display=swap';
    }

    if (theme_option('google_fonts_url') !== '') {
        $urls[] = (string) theme_option('google_fonts_url');
    }

    return $urls;
});

add_action('register_theme_options', function (\LumoraPress\Core\Theme\ThemeOptions $options): void {
    $options->registerSection(
        'lumora_classic',
        'Lumora Classic',
        'Choose the homepage composition used by this theme.'
    );

    $options->registerField(new \LumoraPress\Core\Theme\ThemeOptionField(
        key: 'lumora_classic_home_layout',
        section: 'lumora_classic',
        type: \LumoraPress\Core\Theme\ThemeOptionType::Select,
        label: 'Homepage layout',
        default: 'magazine',
        choices: [
            'magazine' => 'Magazine — featured story and two cards',
            'classic' => 'Classic — one post per row',
        ],
        help: 'The magazine layout gives the first post visual priority and places the remaining posts in a two-column grid.',
    ));

    $options->registerField(new \LumoraPress\Core\Theme\ThemeOptionField(
        key: 'lumora_classic_load_fonts',
        section: 'lumora_classic',
        type: \LumoraPress\Core\Theme\ThemeOptionType::Checkbox,
        label: 'Load Lora and Playfair Display from Google Fonts',
        default: '1',
        help: 'Lora is used for body text and Playfair Display for titles. Turning this off stops visitors\' browsers contacting Google for fonts; the theme then falls back to Georgia.',
    ));
});
