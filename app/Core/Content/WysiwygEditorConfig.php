<?php

/**
 * Normalizes plugin-supplied WYSIWYG editor toolbar configuration.
 *
 * @package LumoraPress
 * @subpackage Content
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.21.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Content;

/**
 * Turns the raw values returned by the WYSIWYG editor filters into the
 * strict shape the browser-side editor reads. Custom buttons are
 * declarative (HTML wrapped around the selection) because the admin CSP
 * forbids plugin-supplied inline JavaScript, and whatever they insert still
 * passes through HtmlSanitizer on save.
 */
final class WysiwygEditorConfig
{
    private const MAX_BUTTONS = 20;
    private const MAX_SNIPPET_LENGTH = 500;
    private const MAX_TITLE_LENGTH = 60;
    private const MAX_TEXT_LENGTH = 12;

    /**
     * @param mixed $hidden Built-in toolbar button names to remove.
     * @param mixed $buttons Custom button definitions: name, title, text (short label), before, after.
     * @param mixed $autosaveSeconds Draft autosave interval.
     * @return array{hide: list<string>, buttons: list<array{name: string, title: string, text: string, before: string, after: string}>, autosaveInterval: string}
     */
    public static function normalize(mixed $hidden, mixed $buttons, mixed $autosaveSeconds): array
    {
        $hide = [];

        foreach (is_array($hidden) ? $hidden : [] as $name) {
            // Mixed case allowed: TinyMCE's own buttons are camelCase (e.g. lumoraFontColor).
            if (is_string($name) && preg_match('/^[A-Za-z0-9-]{1,40}$/', $name) === 1) {
                $hide[] = $name;
            }
        }

        $clean = [];
        $seen = [];

        foreach (is_array($buttons) ? $buttons : [] as $button) {
            if (count($clean) >= self::MAX_BUTTONS || !is_array($button)) {
                break;
            }

            $name = is_string($button['name'] ?? null) ? $button['name'] : '';
            $title = is_string($button['title'] ?? null) ? trim($button['title']) : '';
            $text = is_string($button['text'] ?? null) ? trim($button['text']) : '';
            $before = is_string($button['before'] ?? null) ? $button['before'] : '';
            $after = is_string($button['after'] ?? null) ? $button['after'] : '';

            // A button that inserts nothing, or has no label for assistive
            // technology, is a plugin bug rather than something to render.
            if (
                preg_match('/^[a-z0-9-]{1,40}$/', $name) !== 1
                || isset($seen[$name])
                || $title === ''
                || ($before === '' && $after === '')
                || strlen($before) > self::MAX_SNIPPET_LENGTH
                || strlen($after) > self::MAX_SNIPPET_LENGTH
            ) {
                continue;
            }

            $title = mb_substr($title, 0, self::MAX_TITLE_LENGTH);
            $seen[$name] = true;
            $clean[] = [
                'name' => 'plugin-' . $name,
                'title' => $title,
                // TinyMCE draws its own icon set, not Font Awesome, so a
                // custom button shows a short text label instead.
                'text' => mb_substr($text !== '' ? $text : $title, 0, self::MAX_TEXT_LENGTH),
                'before' => $before,
                'after' => $after,
            ];
        }

        $seconds = is_numeric($autosaveSeconds) ? (int) $autosaveSeconds : 15;
        $seconds = max(
            MarkdownEditorConfig::MIN_AUTOSAVE_SECONDS,
            min(MarkdownEditorConfig::MAX_AUTOSAVE_SECONDS, $seconds),
        );

        return [
            'hide' => array_values(array_unique($hide)),
            'buttons' => $clean,
            // TinyMCE's autosave_interval takes a duration string.
            'autosaveInterval' => $seconds . 's',
        ];
    }
}
