<?php

/**
 * POST-handling logic for the admin Appearance > Menus screen.
 *
 * @package LumoraPress
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.15.0
 */

declare(strict_types=1);

namespace LumoraPress\Controllers\Admin;

use LumoraPress\Core\Menus\MenuManager;
use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Services\CategoryService;
use LumoraPress\Services\PageService;
use LumoraPress\Services\PostService;
use LumoraPress\Services\TagService;

/**
 * POST-handling logic for the admin Appearance > Menus screen. The view
 * stays a thin wrapper: reads $_POST, calls a method here, and turns the
 * returned AdminActionResult into a redirect or an error.
 *
 * Menus + per-location assignment persist as two JSON options
 * ("nav_menus", "nav_menu_locations") on PressConfig, mirroring the
 * view's own pre-extraction persist closures. Every CSRF action name is
 * scoped per menu/item/direction id, the same reasoning ThemesController's
 * own docblock gives for per-slug scoping.
 */
final class MenusController
{
    public function __construct(
        private readonly MenuManager $menus,
        private readonly PressConfig $config,
        private readonly PageService $pages,
        private readonly PostService $posts,
        private readonly CategoryService $categories,
        private readonly TagService $tags,
    ) {
    }

    /**
     * @param array<string, mixed> $post
     */
    public function createMenu(array $post, ?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify('menu_create', $csrfToken)) {
            return $this->invalidRequest();
        }

        $name = trim((string) ($post['name'] ?? ''));

        if ($name === '') {
            return AdminActionResult::error('Please enter a menu name.');
        }

        $newId = $this->menus->createMenu($name);
        $this->persistMenus();

        return AdminActionResult::redirect(admin_url('appearance/menus') . '?menu_id=' . urlencode($newId) . '&saved=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function renameMenu(array $post, ?string $csrfToken): AdminActionResult
    {
        $menuId = trim((string) ($post['menu_id'] ?? ''));

        if (!Csrf::verify('menu_rename_' . $menuId, $csrfToken)) {
            return $this->invalidRequest();
        }

        if ($this->menus->menu($menuId) === null) {
            return AdminActionResult::error('That menu no longer exists.');
        }

        $name = trim((string) ($post['name'] ?? ''));

        if ($name === '') {
            return AdminActionResult::error('Please enter a menu name.');
        }

        $this->menus->renameMenu($menuId, $name);
        $this->persistMenus();

        return AdminActionResult::redirect(admin_url('appearance/menus') . '?menu_id=' . urlencode($menuId) . '&saved=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function duplicateMenu(array $post, ?string $csrfToken): AdminActionResult
    {
        $menuId = trim((string) ($post['menu_id'] ?? ''));

        if (!Csrf::verify('menu_duplicate_' . $menuId, $csrfToken)) {
            return $this->invalidRequest();
        }

        $menu = $this->menus->menu($menuId);

        if ($menu === null) {
            return AdminActionResult::error('That menu no longer exists.');
        }

        $name = trim((string) ($post['name'] ?? '')) ?: ($menu['name'] . ' Copy');
        $newId = $this->menus->duplicateMenu($menuId, $name);
        $this->persistMenus();

        return AdminActionResult::redirect(admin_url('appearance/menus') . '?menu_id=' . urlencode((string) $newId) . '&saved=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function deleteMenu(array $post, ?string $csrfToken): AdminActionResult
    {
        $menuId = trim((string) ($post['menu_id'] ?? ''));

        if (!Csrf::verify('menu_delete_' . $menuId, $csrfToken)) {
            return $this->invalidRequest();
        }

        if ($this->menus->menu($menuId) === null) {
            return AdminActionResult::error('That menu no longer exists.');
        }

        $this->menus->deleteMenu($menuId);
        $this->persistMenus();
        $this->persistLocations();

        return AdminActionResult::redirect(admin_url('appearance/menus') . '?deleted=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function addCustomLink(array $post, ?string $csrfToken): AdminActionResult
    {
        $menuId = trim((string) ($post['menu_id'] ?? ''));

        if (!Csrf::verify('menu_add_custom_link_' . $menuId, $csrfToken)) {
            return $this->invalidRequest();
        }

        if ($this->menus->menu($menuId) === null) {
            return AdminActionResult::error('That menu no longer exists.');
        }

        $label = trim((string) ($post['label'] ?? ''));
        $url = trim((string) ($post['url'] ?? ''));

        if ($label === '' || $url === '') {
            return AdminActionResult::error('Please enter both a label and a URL.');
        }

        $this->menus->addMenuItem($menuId, ['label' => $label, 'url' => $url]);
        $this->persistMenus();

        return AdminActionResult::redirect(admin_url('appearance/menus') . '?menu_id=' . urlencode($menuId) . '&saved=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function addPages(array $post, ?string $csrfToken): AdminActionResult
    {
        return $this->addFromSelection(
            'add_pages',
            $post,
            $csrfToken,
            function (array $selectedIds, string $menuId): void {
                foreach ($this->pages->listAllForMenuSelect() as $page) {
                    if (in_array($page['id'], $selectedIds, true)) {
                        $pageForLink = $this->pages->findById((int) $page['id']);
                        $this->menus->addMenuItem($menuId, ['label' => $page['title'], 'url' => $pageForLink !== null ? page_permalink($pageForLink) : site_url($page['slug'])]);
                    }
                }
            },
        );
    }

    /**
     * @param array<string, mixed> $post
     */
    public function addPosts(array $post, ?string $csrfToken): AdminActionResult
    {
        return $this->addFromSelection(
            'add_posts',
            $post,
            $csrfToken,
            function (array $selectedIds, string $menuId): void {
                foreach ($this->posts->listAllForMenuSelect() as $postRow) {
                    if (in_array($postRow['id'], $selectedIds, true)) {
                        $postForLink = $this->posts->findById((int) $postRow['id']);
                        $this->menus->addMenuItem($menuId, ['label' => $postRow['title'], 'url' => $postForLink !== null ? post_permalink($postForLink) : site_url('post/' . $postRow['slug'])]);
                    }
                }
            },
        );
    }

    /**
     * @param array<string, mixed> $post
     */
    public function addCategories(array $post, ?string $csrfToken): AdminActionResult
    {
        return $this->addFromSelection(
            'add_categories',
            $post,
            $csrfToken,
            function (array $selectedIds, string $menuId): void {
                foreach ($this->categories->listAll() as $category) {
                    if (in_array($category->id, $selectedIds, true)) {
                        $this->menus->addMenuItem($menuId, ['label' => $category->name, 'url' => category_permalink($category)]);
                    }
                }
            },
        );
    }

    /**
     * @param array<string, mixed> $post
     */
    public function addTags(array $post, ?string $csrfToken): AdminActionResult
    {
        return $this->addFromSelection(
            'add_tags',
            $post,
            $csrfToken,
            function (array $selectedIds, string $menuId): void {
                foreach ($this->tags->listAll() as $tag) {
                    if (in_array($tag->id, $selectedIds, true)) {
                        $this->menus->addMenuItem($menuId, ['label' => $tag->name, 'url' => tag_permalink($tag)]);
                    }
                }
            },
        );
    }

    /**
     * Shared shape behind addPages()/addPosts()/addCategories()/addTags():
     * validate the menu and the selection, then hand the actual per-type
     * lookup/addMenuItem() loop to the caller's closure.
     *
     * @param array<string, mixed> $post
     */
    private function addFromSelection(string $csrfPrefix, array $post, ?string $csrfToken, \Closure $addSelected): AdminActionResult
    {
        $menuId = trim((string) ($post['menu_id'] ?? ''));

        if (!Csrf::verify('menu_' . $csrfPrefix . '_' . $menuId, $csrfToken)) {
            return $this->invalidRequest();
        }

        if ($this->menus->menu($menuId) === null) {
            return AdminActionResult::error('That menu no longer exists.');
        }

        $selectedIds = is_array($post['selected_ids'] ?? null) ? array_map('intval', $post['selected_ids']) : [];

        if ($selectedIds === []) {
            return AdminActionResult::error('Please select at least one item to add.');
        }

        $addSelected($selectedIds, $menuId);
        $this->persistMenus();

        return AdminActionResult::redirect(admin_url('appearance/menus') . '?menu_id=' . urlencode($menuId) . '&saved=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function updateItem(array $post, ?string $csrfToken): AdminActionResult
    {
        $menuId = trim((string) ($post['menu_id'] ?? ''));
        $itemId = trim((string) ($post['item_id'] ?? ''));

        if (!Csrf::verify('menu_update_item_' . $itemId, $csrfToken)) {
            return $this->invalidRequest();
        }

        $menu = $this->menus->menu($menuId);

        if ($menu === null) {
            return AdminActionResult::error('That menu no longer exists.');
        }

        $items = $menu['items'];
        $matched = false;

        foreach ($items as $index => $item) {
            if ($item['id'] === $itemId) {
                $parentId = trim((string) ($post['parentId'] ?? ''));
                $descendants = self::descendantIds($items, $itemId);

                // Reject a parent that is this item or one of its own
                // descendants — the only cycle guard needed.
                if ($parentId === $itemId || in_array($parentId, $descendants, true)) {
                    $parentId = '';
                }

                $items[$index] = [
                    'id' => $item['id'],
                    'label' => trim((string) ($post['label'] ?? $item['label'])),
                    'url' => trim((string) ($post['url'] ?? $item['url'])),
                    'target' => ($post['target'] ?? '') === '1' ? '_blank' : '_self',
                    'cssClass' => trim((string) ($post['cssClass'] ?? '')),
                    'rel' => trim((string) ($post['rel'] ?? '')),
                    'titleAttribute' => trim((string) ($post['titleAttribute'] ?? '')),
                    'parentId' => $parentId,
                ];
                $matched = true;

                break;
            }
        }

        if (!$matched) {
            return AdminActionResult::error('That menu item no longer exists.');
        }

        $this->menus->setMenuItems($menuId, $items);
        $this->persistMenus();

        return AdminActionResult::redirect(admin_url('appearance/menus') . '?menu_id=' . urlencode($menuId) . '&saved=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function removeItem(array $post, ?string $csrfToken): AdminActionResult
    {
        $menuId = trim((string) ($post['menu_id'] ?? ''));
        $itemId = trim((string) ($post['item_id'] ?? ''));

        if (!Csrf::verify('menu_remove_item_' . $itemId, $csrfToken)) {
            return $this->invalidRequest();
        }

        if ($this->menus->menu($menuId) === null) {
            return AdminActionResult::error('That menu no longer exists.');
        }

        $this->menus->removeMenuItem($menuId, $itemId);
        $this->persistMenus();

        return AdminActionResult::redirect(admin_url('appearance/menus') . '?menu_id=' . urlencode($menuId) . '&removed=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function moveItem(array $post, ?string $csrfToken): AdminActionResult
    {
        $menuId = trim((string) ($post['menu_id'] ?? ''));
        $itemId = trim((string) ($post['item_id'] ?? ''));
        $direction = (string) ($post['direction'] ?? '');

        if (!Csrf::verify('menu_move_' . $direction . '_' . $itemId, $csrfToken)) {
            return $this->invalidRequest();
        }

        $menu = $this->menus->menu($menuId);

        if ($menu === null) {
            return AdminActionResult::error('That menu no longer exists.');
        }

        $items = $menu['items'];
        $targetIndex = null;

        foreach ($items as $index => $item) {
            if ($item['id'] === $itemId) {
                $targetIndex = $index;

                break;
            }
        }

        if ($targetIndex !== null) {
            // Reorders among true siblings (same parentId), not raw array
            // position, since items with a different parent can sit between.
            $parentId = $items[$targetIndex]['parentId'];
            $siblingIndices = array_keys(array_filter(
                $items,
                static fn (array $item): bool => $item['parentId'] === $parentId,
            ));
            $positionAmongSiblings = array_search($targetIndex, $siblingIndices, true);
            $swapPosition = $direction === 'up' ? $positionAmongSiblings - 1 : $positionAmongSiblings + 1;

            if ($positionAmongSiblings !== false && $swapPosition >= 0 && $swapPosition < count($siblingIndices)) {
                $swapIndex = $siblingIndices[$swapPosition];
                [$items[$targetIndex], $items[$swapIndex]] = [$items[$swapIndex], $items[$targetIndex]];
                $this->menus->setMenuItems($menuId, $items);
                $this->persistMenus();
            }
        }

        return AdminActionResult::redirect(admin_url('appearance/menus') . '?menu_id=' . urlencode($menuId) . '&saved=1');
    }

    /**
     * Splices the dragged item out and back in at the target position;
     * target must share its parentId (server-side backstop for
     * sortable.js's own guard). Re-parenting stays the dropdown's job.
     *
     * @param array<string, mixed> $post
     */
    public function repositionItem(array $post, ?string $csrfToken): AdminActionResult
    {
        $menuId = trim((string) ($post['menu_id'] ?? ''));
        $itemId = trim((string) ($post['item_id'] ?? ''));
        $targetId = trim((string) ($post['target_id'] ?? ''));
        $position = (string) ($post['position'] ?? 'before');

        if (!Csrf::verify('menu_reposition_' . $menuId, $csrfToken)) {
            return $this->invalidRequest();
        }

        $menu = $this->menus->menu($menuId);

        if ($menu === null) {
            return AdminActionResult::error('That menu no longer exists.');
        }

        $items = $menu['items'];
        $draggedIndex = null;

        foreach ($items as $index => $item) {
            if ($item['id'] === $itemId) {
                $draggedIndex = $index;

                break;
            }
        }

        if ($draggedIndex !== null) {
            $dragged = $items[$draggedIndex];
            $remaining = array_values(array_filter(
                $items,
                static fn (array $item): bool => $item['id'] !== $itemId,
            ));

            $targetIndex = null;

            foreach ($remaining as $index => $item) {
                if ($item['id'] === $targetId && $item['parentId'] === $dragged['parentId']) {
                    $targetIndex = $index;

                    break;
                }
            }

            if ($targetIndex !== null) {
                $insertAt = $position === 'after' ? $targetIndex + 1 : $targetIndex;
                array_splice($remaining, $insertAt, 0, [$dragged]);
                $this->menus->setMenuItems($menuId, $remaining);
                $this->persistMenus();
            }
        }

        return AdminActionResult::redirect(admin_url('appearance/menus') . '?menu_id=' . urlencode($menuId) . '&saved=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function saveLocations(array $post, ?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify('menu_save_locations', $csrfToken)) {
            return $this->invalidRequest();
        }

        foreach (array_keys($this->menus->locations()) as $slug) {
            $selectedMenuId = trim((string) ($post['location'][$slug] ?? ''));
            $this->menus->assignMenuToLocation($slug, $selectedMenuId !== '' ? $selectedMenuId : null);
        }

        $this->persistLocations();

        return AdminActionResult::redirect(admin_url('appearance/menus') . '?saved=1');
    }

    /**
     * Every id in $items that is $itemId or a descendant of it — excluded
     * from that item's own "Parent" choices so an item can never become
     * its own ancestor.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, string>
     */
    private static function descendantIds(array $items, string $itemId): array
    {
        $direct = array_column(array_filter($items, static fn (array $item): bool => $item['parentId'] === $itemId), 'id');
        $all = $direct;

        foreach ($direct as $childId) {
            $all = array_merge($all, self::descendantIds($items, $childId));
        }

        return $all;
    }

    private function persistMenus(): void
    {
        $this->config->setOption('nav_menus', json_encode($this->menus->menus()));
    }

    private function persistLocations(): void
    {
        $assignments = [];

        foreach (array_keys($this->menus->locations()) as $slug) {
            $menuId = $this->menus->menuIdForLocation($slug);

            if ($menuId !== null) {
                $assignments[$slug] = $menuId;
            }
        }

        $this->config->setOption('nav_menu_locations', json_encode($assignments));
    }

    /**
     * A failed CSRF check gets an actual error message rather than
     * silently falling through to a normal re-render.
     */
    private function invalidRequest(): AdminActionResult
    {
        return AdminActionResult::error('Your session expired or the request could not be verified. Please try again.');
    }
}
