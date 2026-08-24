<?php

/**
 * Creates a named menu (with its items) from an ImportedMenu DTO and records provenance (LPP-004 Stage 8).
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

use LumoraPress\Core\Menus\MenuManager;
use LumoraPress\Services\ContentImportRegistry;

/**
 * Unlike PageImporter/PostImporter, MenuManager has no database-backed
 * create() of its own — createMenu()/addMenuItem() only mutate its
 * in-memory state (see MenuManager's own docblock). This class does not
 * persist the "nav_menus" option itself; the caller (WordPressImportService)
 * does that once after every menu in a batch has been imported, mirroring
 * admin/views/appearance/menus.php's own $persistMenus closure.
 *
 * Items are resolved parent-before-child via a multi-pass loop (the same
 * approach WordPressImportService already uses for category/folder
 * hierarchies) rather than assuming $menu->items is already in a
 * topological order — a source's own item ordering (WordPress's
 * menu_order, for instance) doesn't guarantee that. An item whose parent
 * never resolves (a genuine data oddity, not normal WordPress state) is
 * added top-level rather than dropped.
 */
final class MenuImporter
{
    public function __construct(
        private readonly MenuManager $menus,
        private readonly ContentImportRegistry $registry,
    ) {
    }

    public function import(string $batchId, string $source, ImportedMenu $menu): string
    {
        $menuId = $this->menus->createMenu($menu->name);

        $remaining = $menu->items;
        usort($remaining, static fn (ImportedMenuItem $a, ImportedMenuItem $b): int => $a->order <=> $b->order);

        /** @var array<string, string> $localIdByExternalId */
        $localIdByExternalId = [];

        while ($remaining !== []) {
            $stillRemaining = [];
            $progressed = false;

            foreach ($remaining as $item) {
                if ($item->parentExternalId === null) {
                    $parentId = null;
                } elseif (isset($localIdByExternalId[$item->parentExternalId])) {
                    $parentId = $localIdByExternalId[$item->parentExternalId];
                } else {
                    $stillRemaining[] = $item;
                    continue;
                }

                $localId = $this->addItem($menuId, $item, $parentId);

                if ($item->externalId !== null) {
                    $localIdByExternalId[$item->externalId] = $localId;
                }

                $progressed = true;
            }

            if (!$progressed) {
                foreach ($stillRemaining as $item) {
                    $localId = $this->addItem($menuId, $item, null);

                    if ($item->externalId !== null) {
                        $localIdByExternalId[$item->externalId] = $localId;
                    }
                }

                $stillRemaining = [];
            }

            $remaining = $stillRemaining;
        }

        $this->registry->record($batchId, $source, 'nav_menu', 0, $menu->externalId);

        return $menuId;
    }

    private function addItem(string $menuId, ImportedMenuItem $item, ?string $parentId): string
    {
        return (string) $this->menus->addMenuItem($menuId, [
            'label' => $item->label,
            'url' => $item->url,
            'target' => $item->target,
            'cssClass' => $item->cssClass,
            'rel' => $item->rel,
            'titleAttribute' => $item->titleAttribute,
            'parentId' => $parentId,
        ]);
    }
}
