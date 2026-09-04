<?php

/**
 * The Font Awesome plugin's main file: plugin metadata header and bootstrap.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

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

use LumoraPress\Core\Shortcodes\ShortcodeField;
use LumoraPress\Core\Shortcodes\ShortcodeFieldType;

require_once __DIR__ . '/src/FontAwesomeService.php';

$fontAwesome = FontAwesomeService::instance();
$fontAwesome->configurePluginsPath(dirname(__DIR__));

add_filter('lp_fontawesome_enabled', static fn (bool $enabled): bool => $fontAwesome->isEnabled());

// Lets the admin icon picker lazy-load real icon glyphs matching whatever
// delivery/version/self-hosted URL is actually configured, without those
// views needing a direct FontAwesomeService reference.
add_filter('lp_fontawesome_css_urls', static fn (array $urls): array => $fontAwesome->cssUrls());

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

// 'style'/'label' are separate fields, not embedded in the 'name' Icon picker —
// some icons support more than one style, and 'label' has no picker equivalent.
// 'color'/'class'/'animation' stay off the picker, still typeable by hand.
add_action('register_shortcodes', static function (): void {
    register_shortcode('icon', 'Icon', [
        new ShortcodeField('name', 'Icon', ShortcodeFieldType::Icon, required: true, help: 'Choose an icon from the library.'),
        new ShortcodeField('style', 'Style', ShortcodeFieldType::Select, default: 'solid', choices: [
            'solid' => 'Solid',
            'regular' => 'Regular',
            'brands' => 'Brands',
            'light' => 'Light',
            'thin' => 'Thin',
            'duotone' => 'Duotone',
        ]),
        new ShortcodeField('label', 'Accessible Label', help: 'Optional — read aloud by screen readers. Leave blank for a purely decorative icon.'),
    ]);
});

add_action('head_assets', static function () use ($fontAwesome): void {
    $fontAwesome->printHeadLinks();
});

add_filter('csp_directives', static fn (array $directives): array => $fontAwesome->filterCsp($directives));
