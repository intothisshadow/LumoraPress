<?php

/**
 * The control types the shortcode insert picker knows how to render (LP-110).
 *
 * @package LumoraPress
 * @subpackage Shortcodes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Shortcodes;

/**
 * Control types the shortcode insert picker (admin/assets/js/
 * content-editor.js's openShortcodePicker()) knows how to render and
 * apply. Only what the three real consumers actually need — a new
 * type needs a matching branch added to the picker's own field-rendering
 * code before a ShortcodeField can use it.
 */
enum ShortcodeFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Checkbox = 'checkbox';
    case Select = 'select';

    /**
     * Opens Font Awesome's existing icon-browser dialog
     * (openIconPicker()) instead of a plain control — the same picker
     * the dedicated "Insert Icon" toolbar button already uses. Choosing
     * an icon fills this field with the icon's slug, and also fills a
     * sibling field named 'style' if the shortcode registered one, since
     * the icon browser already knows which style the chosen icon has.
     */
    case Icon = 'icon';
}
