<?php

/**
 * The control types the Theme Options admin UI knows how to render and validate (LP-034).
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

namespace LumoraPress\Core\Theme;

/**
 * Control types the Theme Options admin UI knows how to render and
 * validate. A new ThemeOptionField can only use one of the cases below
 * until a matching branch is added to ThemeOptions and the admin view.
 */
enum ThemeOptionType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Checkbox = 'checkbox';
    case Select = 'select';
    case Color = 'color';
    case Url = 'url';

    /**
     * Rich Markdown/HTML/Plain content, reusing content-editor.js's
     * WYSIWYG/Markdown/HTML toggle. Stored raw and sanitized only at
     * render time via render_content(), same posture as post/page content.
     * Always paired with a companion Select field carrying the format
     * (e.g. welcome_message + welcome_message_format).
     */
    case Html = 'html';
}
