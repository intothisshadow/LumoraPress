<?php

/**
 * Navigation menu locations and named, reusable menus (LP-049), assignable to any theme-registered location.
 *
 * @package LumoraPress
 * @subpackage Menus
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Menus;

/**
 * Navigation menu locations (primary, footer, social, secondary — theme-
 * registered via registerLocation()) and named, reusable menus (LP-049,
 * admin-managed) that can be assigned to any location. Mirrors
 * WidgetManager's LP-048 shape: named entities with stable string ids,
 * built for the "one JSON option, load at bootstrap, admin screen
 * mutates and re-saves the whole thing" persistence pattern.
 *
 * assign()/items()/hasItems() predate named menus (Phase 1) and assign
 * items directly to a location with no menu entity in between — kept
 * unchanged for backward compatibility (nothing in this codebase's
 * production code calls assign() today, but it remains a valid, simpler
 * way for a theme/plugin to wire up a location programmatically without
 * the admin UI at all). items()/hasItems() check for an assigned named
 * menu first and only fall back to a direct assign()-populated location
 * if no menu is assigned there.
 */
final class MenuManager
{
    /** @var array<string, string> */
    private array $locations = [];

    /** @var array<string, array<int, array{label: string, url: string, target: string}>> */
    private array $assignments = [];

    /** @var array<string, array{name: string, items: array<int, array<string, mixed>>}> */
    private array $menus = [];

    /** @var array<string, string> location slug => menu id */
    private array $locationAssignments = [];

    public function registerLocation(string $slug, string $label): void
    {
        $this->locations[$slug] = $label;
    }

    /**
     * @return array<string, string>
     */
    public function locations(): array
    {
        return $this->locations;
    }

    /**
     * @param array<int, array{label: string, url: string, target?: string}> $items
     */
    public function assign(string $location, array $items): void
    {
        $this->assignments[$location] = array_map(
            static fn (array $item): array => [
                'label' => $item['label'],
                'url' => $item['url'],
                'target' => $item['target'] ?? '_self',
            ],
            $items,
        );
    }

    public function createMenu(string $name, ?string $id = null): string
    {
        $id ??= self::generateId();
        $this->menus[$id] = ['name' => $name, 'items' => []];

        return $id;
    }

    public function renameMenu(string $id, string $name): void
    {
        if (isset($this->menus[$id])) {
            $this->menus[$id]['name'] = $name;
        }
    }

    public function deleteMenu(string $id): void
    {
        unset($this->menus[$id]);

        $this->locationAssignments = array_filter(
            $this->locationAssignments,
            static fn (string $menuId): bool => $menuId !== $id,
        );
    }

    /**
     * @return string|null the new menu's id, or null if $id doesn't exist
     */
    public function duplicateMenu(string $id, string $newName): ?string
    {
        if (!isset($this->menus[$id])) {
            return null;
        }

        $newId = self::generateId();
        $this->menus[$newId] = [
            'name' => $newName,
            'items' => array_map(
                static fn (array $item): array => [...$item, 'id' => self::generateId()],
                $this->menus[$id]['items'],
            ),
        ];

        return $newId;
    }

    /**
     * @return array<string, array{name: string, items: array<int, array<string, mixed>>}>
     */
    public function menus(): array
    {
        return $this->menus;
    }

    /**
     * @return array{name: string, items: array<int, array<string, mixed>>}|null
     */
    public function menu(string $id): ?array
    {
        return $this->menus[$id] ?? null;
    }

    /**
     * Replaces every item in $menuId with $items — used to load persisted
     * menus at bootstrap and to save the admin Menus screen's form. Each
     * entry must have 'id', 'label', 'url'; missing ids get one generated
     * so callers restoring from storage that predates ids still work.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public function setMenuItems(string $menuId, array $items): void
    {
        if (!isset($this->menus[$menuId])) {
            return;
        }

        $this->menus[$menuId]['items'] = array_values(array_map(
            static fn (array $item): array => [
                'id' => $item['id'] ?? self::generateId(),
                'label' => (string) ($item['label'] ?? ''),
                'url' => (string) ($item['url'] ?? ''),
                'target' => ($item['target'] ?? '_self') === '_blank' ? '_blank' : '_self',
                'cssClass' => (string) ($item['cssClass'] ?? ''),
                'rel' => (string) ($item['rel'] ?? ''),
                'titleAttribute' => (string) ($item['titleAttribute'] ?? ''),
                'parentId' => ($item['parentId'] ?? '') !== '' ? (string) $item['parentId'] : null,
            ],
            $items,
        ));
    }

    /**
     * @param array<string, mixed> $item
     */
    public function addMenuItem(string $menuId, array $item): ?string
    {
        if (!isset($this->menus[$menuId])) {
            return null;
        }

        $id = self::generateId();
        $this->menus[$menuId]['items'][] = [
            'id' => $id,
            'label' => (string) ($item['label'] ?? ''),
            'url' => (string) ($item['url'] ?? ''),
            'target' => ($item['target'] ?? '_self') === '_blank' ? '_blank' : '_self',
            'cssClass' => (string) ($item['cssClass'] ?? ''),
            'rel' => (string) ($item['rel'] ?? ''),
            'titleAttribute' => (string) ($item['titleAttribute'] ?? ''),
            'parentId' => ($item['parentId'] ?? '') !== '' ? (string) $item['parentId'] : null,
        ];

        return $id;
    }

    public function removeMenuItem(string $menuId, string $itemId): void
    {
        if (!isset($this->menus[$menuId])) {
            return;
        }

        // Removing an item promotes its own children to its former parent
        // rather than deleting a whole subtree, so a slip of the "Remove"
        // button can't silently wipe out an entire nested section.
        $parentId = null;

        foreach ($this->menus[$menuId]['items'] as $item) {
            if ($item['id'] === $itemId) {
                $parentId = $item['parentId'];

                break;
            }
        }

        $this->menus[$menuId]['items'] = array_values(array_map(
            static function (array $item) use ($itemId, $parentId): array {
                if ($item['parentId'] === $itemId) {
                    $item['parentId'] = $parentId;
                }

                return $item;
            },
            array_filter(
                $this->menus[$menuId]['items'],
                static fn (array $item): bool => $item['id'] !== $itemId,
            ),
        ));
    }

    public function assignMenuToLocation(string $location, ?string $menuId): void
    {
        if ($menuId === null || $menuId === '') {
            unset($this->locationAssignments[$location]);

            return;
        }

        $this->locationAssignments[$location] = $menuId;
    }

    public function menuIdForLocation(string $location): ?string
    {
        return $this->locationAssignments[$location] ?? null;
    }

    /**
     * @return array<int, array{label: string, url: string, target: string}>
     */
    public function items(string $location): array
    {
        $menuId = $this->menuIdForLocation($location);

        if ($menuId !== null && isset($this->menus[$menuId])) {
            return $this->menus[$menuId]['items'];
        }

        return $this->assignments[$location] ?? [];
    }

    /**
     * The items assigned to $location, nested into a nav_menu()-ready tree
     * via each item's parentId — unlimited depth, same "flat rows in, tree
     * out" shape CommentService::publicTreeForPost() already uses for
     * comment replies.
     *
     * @return array<int, array{item: array<string, mixed>, children: array<mixed>}>
     */
    public function itemTree(string $location): array
    {
        return self::buildTree($this->items($location), null);
    }

    public function hasItems(string $location): bool
    {
        return $this->items($location) !== [];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array{item: array<string, mixed>, children: array<mixed>}>
     */
    private static function buildTree(array $items, ?string $parentId): array
    {
        $branch = [];

        foreach ($items as $item) {
            if (($item['parentId'] ?? null) !== $parentId) {
                continue;
            }

            // Legacy assign()-populated items (Phase 1, no 'id' key at
            // all) always have a null parentId too — recursing with
            // $item['id'] ?? null would look for children matching that
            // same null again, immediately re-matching every sibling and
            // recursing forever. Only items with a real id can ever be a
            // parent.
            $itemId = $item['id'] ?? null;

            $branch[] = [
                'item' => $item,
                'children' => $itemId !== null ? self::buildTree($items, $itemId) : [],
            ];
        }

        return $branch;
    }

    private static function generateId(): string
    {
        return bin2hex(random_bytes(6));
    }
}
