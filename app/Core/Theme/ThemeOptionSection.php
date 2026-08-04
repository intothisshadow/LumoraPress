<?php

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
