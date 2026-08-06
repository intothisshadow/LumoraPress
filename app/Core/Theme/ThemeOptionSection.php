<?php

/**
 * A group of related Theme Options, rendered as one panel on the Theme Options admin page (LP-034).
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
 * A group of related Theme Options, rendered as one panel on the Theme
 * Options admin page (LP-034's "Group options into sections").
 */
final class ThemeOptionSection
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description = '',
    ) {
    }
}
