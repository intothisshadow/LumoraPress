<?php

/**
 * Normalizes plugin-supplied Markdown editor toolbar configuration.
 *
 * @package LumoraPress
 * @subpackage Content
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.19.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Content;

/**
 * Turns the raw values returned by the Markdown editor filters into the
 * strict shape the browser-side editor reads. Custom buttons are
 * declarative (text wrapped around the selection) because the admin CSP
 * forbids plugin-supplied inline JavaScript.
 */
final class MarkdownEditorConfig
{
    public const MIN_AUTOSAVE_SECONDS = 5;
    public const MAX_AUTOSAVE_SECONDS = 300;

    private const MAX_BUTTONS = 20;
    private const MAX_SNIPPET_LENGTH = 500;
    private const MAX_TITLE_LENGTH = 60;

    /**
     * @param mixed $hidden Built-in button names to remove.
     * @param mixed $buttons Custom button definitions: name, title, icon (Font Awesome 4 class), before, after.
     * @param mixed $autosaveSeconds Draft autosave interval.
     * @return array{hide: list<string>, buttons: list<array{name: string, title: string, icon: string, before: string, after: string}>, autosaveDelay: int}
     */
    public static function normalize(mixed $hidden, mixed $buttons, mixed $autosaveSeconds): array
    {
        $hide = [];

        foreach (is_array($hidden) ? $hidden : [] as $name) {
            if (is_string($name) && preg_match('/^[a-z0-9-]{1,40}$/', $name) === 1) {
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
            $icon = is_string($button['icon'] ?? null) ? $button['icon'] : '';
            $before = is_string($button['before'] ?? null) ? $button['before'] : '';
            $after = is_string($button['after'] ?? null) ? $button['after'] : '';

            // A button that inserts nothing, or has no label for assistive
            // technology, is a plugin bug rather than something to render.
            if (
                preg_match('/^[a-z0-9-]{1,40}$/', $name) !== 1
                || isset($seen[$name])
                || $title === ''
                || preg_match('/^fa-[a-z0-9-]{1,40}$/', $icon) !== 1
                || ($before === '' && $after === '')
                || strlen($before) > self::MAX_SNIPPET_LENGTH
                || strlen($after) > self::MAX_SNIPPET_LENGTH
            ) {
                continue;
            }

            $seen[$name] = true;
            $clean[] = [
                'name' => 'plugin-' . $name,
                'title' => mb_substr($title, 0, self::MAX_TITLE_LENGTH),
                'icon' => $icon,
                'before' => $before,
                'after' => $after,
            ];
        }

        $seconds = is_numeric($autosaveSeconds) ? (int) $autosaveSeconds : 15;
        $seconds = max(self::MIN_AUTOSAVE_SECONDS, min(self::MAX_AUTOSAVE_SECONDS, $seconds));

        return [
            'hide' => array_values(array_unique($hide)),
            'buttons' => $clean,
            'autosaveDelay' => $seconds * 1000,
        ];
    }
}
