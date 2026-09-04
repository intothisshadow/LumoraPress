<?php

/**
 * Procedural shortcode-registration API for plugins (register_shortcode()).
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */

declare(strict_types=1);

use LumoraPress\Core\Shortcodes\ShortcodeField;
use LumoraPress\Core\Shortcodes\Shortcodes;

/**
 * Procedural shortcode-registration API — adds a `[name]` shortcode to
 * the editor toolbar's "Insert Shortcode" picker. Metadata only (the
 * picker's form fields); rendering still goes through `content_html`.
 *
 * A plugin needing database-backed choices can't call this from its own
 * top-level file (loaded before Kernel exists) — hook 'register_shortcodes'
 * instead, fired once Kernel is built:
 *
 *     add_action('register_shortcodes', function ($registry, $kernel) {
 *         register_shortcode('my_shortcode', 'My Shortcode', [...]);
 *     });
 */

if (!function_exists('register_shortcode')) {
    /**
     * @param array<int, ShortcodeField> $fields
     */
    function register_shortcode(string $name, string $label, array $fields = []): void
    {
        Shortcodes::instance()->register($name, $label, $fields);
    }
}
