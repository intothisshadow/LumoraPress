<?php

/**
 * A plain data holder describing one nav menu (name + items) to create via MenuImporter (LPP-004 Stage 8), independent of where the data came from.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Services\Import;

final class ImportedMenu
{
    /**
     * @param array<int, ImportedMenuItem> $items
     */
    public function __construct(
        public readonly string $name,
        public readonly array $items,
        public readonly ?string $externalId = null,
    ) {
    }
}
