<?php

/**
 * Default theme setup: registers widget areas and navigation menu locations.
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

register_nav_menu('primary', 'Primary Menu');
register_nav_menu('footer', 'Footer Menu');
register_nav_menu('social', 'Social Links Menu');
register_nav_menu('secondary', 'Secondary Menu');

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
});
