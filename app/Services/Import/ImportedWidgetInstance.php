<?php

/**
 * A plain data holder describing one widget instance to create via WidgetImporter (LPP-004 Stage 8), independent of where the data came from.
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

/**
 * $type is already a Lumora Press widget type slug (e.g. 'text'), and
 * $settings already uses Lumora Press's own setting keys — the source
 * importer maps its own widget-type/setting names onto these before
 * building this DTO (see WordPressImportService::importWidgets()'s type
 * map), the same "already-resolved, source-agnostic" shape every other
 * Imported* DTO uses. $targetSidebarId is the *local* sidebar id
 * (including WidgetManager::INACTIVE_SIDEBAR_ID) WidgetImporter should
 * place this instance into — area-matching happens in the source
 * importer too, not here.
 */
final class ImportedWidgetInstance
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        public readonly string $type,
        public readonly array $settings,
        public readonly string $targetSidebarId,
        public readonly int $order,
        public readonly ?string $externalId = null,
    ) {
    }
}
