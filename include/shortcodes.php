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
 * Procedural shortcode-registration API (LP-110) — adds a `[name]`
 * shortcode to the editor toolbar's "Insert Shortcode" picker. This is
 * metadata only (the picker's form fields); it never changes what a
 * `[name ...]` typed by hand renders as — that still goes through the
 * existing `content_html` filter (add_filter()) exactly as before.
 *
 * A plugin needing choices/data that only exist once real services are
 * available (e.g. a live list of Folders or Download categories) can't
 * call this directly from its own top-level file — a plugin's main file
 * loads before Kernel exists, the same constraint documented in
 * DEVELOPER-APIS.md's "Admin UI extension points" section for widgets/
 * menus. Hook the 'register_shortcodes' action instead (fired once,
 * after Kernel is fully built — see bootstrap.php):
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
