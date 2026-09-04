<?php

/**
 * The Emoji Picker plugin's main file: plugin metadata header and bootstrap.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.9.0
 */

declare(strict_types=1);

/*
 * Plugin Name: Emoji Picker
 * Plugin URI: https://lumorapress.org/plugins/emoji-picker
 * Description: A fast, fully offline emoji picker for the post/page editor toolbar — search or browse by category, a per-user recently-used list, and a small developer API (lp_emoji_picker_button(), an lp_emoji_dataset filter, an lp_emoji_inserted action) for extending it or reusing the trigger elsewhere.
 * Version: 0.1.0
 * Author: Lumora Press
 * Author URI: https://lumorapress.org
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Tags: editor, emoji, writing, developer
 * Requires at least: 0.9.0
 * Requires PHP: 8.2
 */

namespace LumoraPress\Plugins\EmojiPicker;

require_once __DIR__ . '/src/EmojiPickerService.php';

$emojiPicker = EmojiPickerService::instance();

add_filter('lp_emoji_picker_enabled', static fn (bool $enabled): bool => $emojiPicker->isEnabled());

// Lets editor-hosting admin views ask "should the picker button show for
// this editor" without a direct EmojiPickerService reference.
add_filter('lp_emoji_picker_editor_enabled', static fn (bool $enabled, string $editor): bool => $emojiPicker->editorEnabled($editor), 10);

// Lets editor-hosting admin views build their own data-emoji-* editor
// container attributes without a direct EmojiPickerService reference.
add_filter('lp_emoji_picker_data', static function (array $data) use ($emojiPicker): array {
    return [
        'dataset' => $emojiPicker->dataset(),
        'defaultCategory' => $emojiPicker->defaultCategory(),
        'recentLimit' => $emojiPicker->recentLimit(),
    ];
});

/*
 * Template tag facade: lp_emoji_picker_button() in include/helpers.php
 * calls apply_filters('lp_emoji_picker_trigger_html', '', $args)
 * unconditionally — safe to call from theme/plugin markup even when this
 * plugin is inactive, since the filter just never runs and the default
 * '' passes straight through.
 */
add_filter('lp_emoji_picker_trigger_html', static function (string $html, array $args) use ($emojiPicker): string {
    if (!$emojiPicker->isEnabled()) {
        return $html;
    }

    $label = isset($args['label']) ? (string) $args['label'] : 'Insert Emoji';
    $target = isset($args['target']) ? (string) $args['target'] : '';

    return '<button type="button" class="lp-emoji-picker-trigger" data-lp-emoji-picker-trigger'
        . ' data-emoji-dataset="' . htmlspecialchars((string) json_encode($emojiPicker->dataset()), ENT_QUOTES, 'UTF-8') . '"'
        . ' data-emoji-default-category="' . htmlspecialchars($emojiPicker->defaultCategory(), ENT_QUOTES, 'UTF-8') . '"'
        . ($target !== '' ? ' data-emoji-target="' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '"' : '')
        . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>';
});
