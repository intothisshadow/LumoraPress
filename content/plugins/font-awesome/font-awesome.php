<?php

declare(strict_types=1);

/*
 * Plugin Name: Font Awesome
 * Plugin URI: https://lumorapress.org/plugins/font-awesome
 * Description: First-class Font Awesome icon support for themes and plugins — configurable CDN or self-hosted delivery, an [icon] shortcode, and a small developer API (lp_icon(), lp_fontawesome_enqueue(), lp_register_icon_pack()) — without every theme/plugin bundling its own copy.
 * Version: 0.1.0
 * Author: Lumora Press
 * Author URI: https://lumorapress.org
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Tags: icons, font awesome, developer
 * Requires at least: 0.4.0
 * Requires PHP: 8.2
 */

namespace LumoraPress\Plugins\FontAwesome;

require_once __DIR__ . '/src/FontAwesomeService.php';

$fontAwesome = FontAwesomeService::instance();

add_filter('lp_fontawesome_enabled', static fn (bool $enabled): bool => $fontAwesome->isEnabled());

add_action('lp_fontawesome_enqueue', static function () use ($fontAwesome): void {
    $fontAwesome->markUsed();
});

add_filter('lp_icon', static fn (string $html, string $name, array $args): string => $fontAwesome->icon($name, $args));

add_action('lp_register_icon_pack', static function (string $key, array $config) use ($fontAwesome): void {
    $fontAwesome->registerIconPack($key, $config);
});

// Priority 20: runs after any other 'content_html' subscriber that might
// still be operating on unrendered markup — this plugin only cares about
// the final sanitized HTML (see renderShortcodes()'s own docblock).
add_filter('content_html', static fn (string $html): string => $fontAwesome->renderShortcodes($html), 20);

add_action('head_assets', static function () use ($fontAwesome): void {
    $fontAwesome->printHeadLinks();
});

add_filter('csp_directives', static fn (array $directives): array => $fontAwesome->filterCsp($directives));
