<?php

/**
 * Appends widget instances from ImportedWidgetInstance DTOs into their target sidebars and records provenance (LPP-004 Stage 8).
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

use LumoraPress\Core\Widgets\WidgetManager;
use LumoraPress\Services\ContentImportRegistry;

/**
 * Unlike MediaImporter/PostImporter, WidgetManager has no database-backed
 * create() — setWidgets() only mutates its in-memory state (see
 * WidgetManager's own docblock). This class does not persist the
 * "widgets_config" option itself; the caller (WordPressImportService)
 * does that once after every widget in a batch has been imported,
 * mirroring admin/views/appearance/widgets.php's own save closure.
 *
 * Appends to whatever a sidebar already holds (via widgetsFor()) rather
 * than replacing it outright — importing must never wipe out widgets a
 * site already had before the import ran.
 */
final class WidgetImporter
{
    public function __construct(
        private readonly WidgetManager $widgets,
        private readonly ContentImportRegistry $registry,
    ) {
    }

    /**
     * @param array<int, ImportedWidgetInstance> $instances
     */
    public function import(string $batchId, string $source, array $instances): void
    {
        $bySidebar = [];

        foreach ($instances as $instance) {
            $bySidebar[$instance->targetSidebarId][] = $instance;
        }

        foreach ($bySidebar as $sidebarId => $sidebarInstances) {
            usort($sidebarInstances, static fn (ImportedWidgetInstance $a, ImportedWidgetInstance $b): int => $a->order <=> $b->order);

            $existing = $this->widgets->widgetsFor($sidebarId);
            $appended = array_map(
                static fn (ImportedWidgetInstance $instance): array => ['type' => $instance->type, 'settings' => $instance->settings],
                $sidebarInstances,
            );

            $this->widgets->setWidgets($sidebarId, [...$existing, ...$appended]);

            foreach ($sidebarInstances as $instance) {
                $this->registry->record($batchId, $source, 'widget_instance', 0, $instance->externalId);
            }
        }
    }
}
