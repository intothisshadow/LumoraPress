<?php

/**
 * Shortcode registration for the editor toolbar's "Insert Shortcode" picker (LP-110).
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
 * Shortcode registration for the editor toolbar's "Insert Shortcode"
 * picker — distinct from the pre-existing `content_html` filter
 * (add_filter('content_html', ...)), which only ever wired up
 * *rendering*. A plugin's shortcode can (and, for Font Awesome/
 * WordPress Importer/Downloads, already does) render via that filter
 * with no entry here at all; registering here only adds picker metadata
 * (name/label/attribute fields), so it never changes what a
 * `[shortcode ...]` already typed by hand renders as.
 *
 * Mirrors WidgetManager's registry shape (register once, enumerate many)
 * — see its own docblock for the precedent this follows.
 */
final class ShortcodeManager
{
    /** @var array<string, array{label: string, fields: array<int, ShortcodeField>}> */
    private array $shortcodes = [];

    /**
     * @param array<int, ShortcodeField> $fields
     */
    public function register(string $name, string $label, array $fields = []): void
    {
        $this->shortcodes[$name] = ['label' => $label, 'fields' => $fields];
    }

    /**
     * @return array<string, array{label: string, fields: array<int, ShortcodeField>}>
     */
    public function all(): array
    {
        return $this->shortcodes;
    }

    /**
     * Plain-array form of the registry, ready for json_encode() into the
     * editor container's data-shortcodes attribute (admin/assets/js/
     * content-editor.js's openShortcodePicker() reads it back) — kept
     * here rather than duplicated across posts/new.php, pages/new.php,
     * and downloads/add-new.php, the three views that each need it.
     *
     * @return array<string, array{label: string, fields: array<int, array{name: string, label: string, type: string, default: string, required: bool, choices: array<string, string>, help: string}>}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (array $shortcode): array => [
                'label' => $shortcode['label'],
                'fields' => array_map(
                    static fn (ShortcodeField $field): array => [
                        'name' => $field->name,
                        'label' => $field->label,
                        'type' => $field->type->value,
                        'default' => $field->default,
                        'required' => $field->required,
                        'choices' => $field->choices,
                        'help' => $field->help,
                    ],
                    $shortcode['fields'],
                ),
            ],
            $this->shortcodes,
        );
    }
}
