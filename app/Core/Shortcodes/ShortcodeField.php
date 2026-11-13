<?php

/**
 * A single attribute field on a registered shortcode (LP-110).
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
 * A single attribute field on a registered shortcode — what the insert
 * picker's form shows for one `[shortcode attribute="..."]` attribute.
 * Immutable, mirroring ThemeOptionField's own shape: a shortcode's field
 * list is registered once per request and never mutated afterward.
 *
 * The picker omits an attribute from the inserted shortcode entirely
 * when its value equals $default — an admin who never touches a field
 * gets the same minimal `[shortcode]` text they'd have typed by hand,
 * not every optional attribute spelled out at its default value.
 * $alwaysInclude overrides that for a field whose empty attribute is the
 * point, such as a blank slot the author pastes ids into.
 */
final class ShortcodeField
{
    /**
     * @param array<string, string>|\Closure(): array<string, string> $choices value => label, Select only.
     *        A closure defers database-backed lookups until the editor picker actually needs them,
     *        so front-end requests never pay for an admin-only dropdown.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly ShortcodeFieldType $type = ShortcodeFieldType::Text,
        public readonly string $default = '',
        public readonly bool $required = false,
        public readonly array|\Closure $choices = [],
        public readonly string $help = '',
        public readonly bool $alwaysInclude = false,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function resolvedChoices(): array
    {
        return $this->choices instanceof \Closure ? ($this->choices)() : $this->choices;
    }
}
