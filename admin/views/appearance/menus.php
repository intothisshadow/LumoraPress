<?php

/**
 * The admin Appearance > Menus screen (LP-049): build and assign navigation menus.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Menus + per-location assignment persist as two JSON options
// ("nav_menus", "nav_menu_locations"). Each form mutates the in-memory
// MenuManager, then re-saves the relevant option.
$persistMenus = static function () use ($kernel): void {
    $kernel->config->setOption('nav_menus', json_encode($kernel->menus->menus()));
};

$persistLocations = static function () use ($kernel): void {
    $assignments = [];

    foreach (array_keys($kernel->menus->locations()) as $slug) {
        $menuId = $kernel->menus->menuIdForLocation($slug);

        if ($menuId !== null) {
            $assignments[$slug] = $menuId;
        }
    }

    $kernel->config->setOption('nav_menu_locations', json_encode($assignments));
};

/**
 * Every id in $items that is $itemId or a descendant of it — excluded
 * from that item's own "Parent" <select> so an item can never become its
 * own ancestor.
 *
 * @param array<int, array<string, mixed>> $items
 * @return array<int, string>
 */
$descendantIds = static function (array $items, string $itemId) use (&$descendantIds): array {
    $direct = array_column(array_filter($items, static fn (array $item): bool => $item['parentId'] === $itemId), 'id');
    $all = $direct;

    foreach ($direct as $childId) {
        $all = array_merge($all, $descendantIds($items, $childId));
    }

    return $all;
};

/**
 * Flattens $items into document order with each item's nesting depth —
 * for the editable "Menu structure" list, same shape MenuManager::
 * itemTree() builds for public rendering, but flat-with-depth rather than
 * nested, since the editor shows one row per item.
 *
 * @param array<int, array<string, mixed>> $items
 * @return array<int, array{item: array<string, mixed>, depth: int}>
 */
$flattenForDisplay = static function (array $items, ?string $parentId = null, int $depth = 0) use (&$flattenForDisplay): array {
    $result = [];

    foreach ($items as $item) {
        if (($item['parentId'] ?? null) !== $parentId) {
            continue;
        }

        $result[] = ['item' => $item, 'depth' => $depth];
        $result = [...$result, ...$flattenForDisplay($items, $item['id'], $depth + 1)];
    }

    return $result;
};

$error = null;
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$postedMenuId = trim((string) ($_POST['menu_id'] ?? ''));
$postedItemId = trim((string) ($_POST['item_id'] ?? ''));
$postedDirection = (string) ($_POST['direction'] ?? '');
$postedTargetId = trim((string) ($_POST['target_id'] ?? ''));
$postedPosition = (string) ($_POST['position'] ?? 'before');
$postedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

// Many forms render on this same page load, so each CSRF action name is
// scoped to the specific menu/item/direction id it acts on.
$csrfAction = match ($form) {
    'create_menu' => 'menu_create',
    'rename_menu' => 'menu_rename_' . $postedMenuId,
    'duplicate_menu' => 'menu_duplicate_' . $postedMenuId,
    'delete_menu' => 'menu_delete_' . $postedMenuId,
    'add_custom_link' => 'menu_add_custom_link_' . $postedMenuId,
    'add_pages' => 'menu_add_pages_' . $postedMenuId,
    'add_posts' => 'menu_add_posts_' . $postedMenuId,
    'add_categories' => 'menu_add_categories_' . $postedMenuId,
    'add_tags' => 'menu_add_tags_' . $postedMenuId,
    'update_item' => 'menu_update_item_' . $postedItemId,
    'remove_item' => 'menu_remove_item_' . $postedItemId,
    'move_item' => 'menu_move_' . $postedDirection . '_' . $postedItemId,
    // One reposition form per menu — sortable.js fills its
    // dragged/target/position fields and submits on drop.
    'reposition_item' => 'menu_reposition_' . $postedMenuId,
    'save_locations' => 'menu_save_locations',
    default => 'menu_unknown_form',
};

$redirectMenuId = $postedMenuId;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && Csrf::verify($csrfAction, $postedToken)) {
    if ($form === 'create_menu') {
        $name = trim((string) ($_POST['name'] ?? ''));

        if ($name === '') {
            $error = 'Please enter a menu name.';
        } else {
            $newId = $kernel->menus->createMenu($name);
            $persistMenus();

            header('Location: ' . admin_url('appearance/menus') . '?menu_id=' . urlencode($newId) . '&saved=1');
            exit;
        }
    } elseif ($form === 'save_locations') {
        foreach (array_keys($kernel->menus->locations()) as $slug) {
            $selectedMenuId = trim((string) ($_POST['location'][$slug] ?? ''));
            $kernel->menus->assignMenuToLocation($slug, $selectedMenuId !== '' ? $selectedMenuId : null);
        }

        $persistLocations();

        header('Location: ' . admin_url('appearance/menus') . '?saved=1');
        exit;
    } elseif ($kernel->menus->menu($postedMenuId) === null) {
        $error = 'That menu no longer exists.';
    } elseif ($form === 'rename_menu') {
        $name = trim((string) ($_POST['name'] ?? ''));

        if ($name === '') {
            $error = 'Please enter a menu name.';
        } else {
            $kernel->menus->renameMenu($postedMenuId, $name);
            $persistMenus();

            header('Location: ' . admin_url('appearance/menus') . '?menu_id=' . urlencode($postedMenuId) . '&saved=1');
            exit;
        }
    } elseif ($form === 'duplicate_menu') {
        $name = trim((string) ($_POST['name'] ?? '')) ?: ($kernel->menus->menu($postedMenuId)['name'] . ' Copy');
        $newId = $kernel->menus->duplicateMenu($postedMenuId, $name);
        $persistMenus();

        header('Location: ' . admin_url('appearance/menus') . '?menu_id=' . urlencode((string) $newId) . '&saved=1');
        exit;
    } elseif ($form === 'delete_menu') {
        $kernel->menus->deleteMenu($postedMenuId);
        $persistMenus();
        $persistLocations();

        header('Location: ' . admin_url('appearance/menus') . '?deleted=1');
        exit;
    } elseif ($form === 'add_custom_link') {
        $label = trim((string) ($_POST['label'] ?? ''));
        $url = trim((string) ($_POST['url'] ?? ''));

        if ($label === '' || $url === '') {
            $error = 'Please enter both a label and a URL.';
        } else {
            $kernel->menus->addMenuItem($postedMenuId, ['label' => $label, 'url' => $url]);
            $persistMenus();

            header('Location: ' . admin_url('appearance/menus') . '?menu_id=' . urlencode($postedMenuId) . '&saved=1');
            exit;
        }
    } elseif (in_array($form, ['add_pages', 'add_posts', 'add_categories', 'add_tags'], true)) {
        $selectedIds = is_array($_POST['selected_ids'] ?? null) ? array_map('intval', $_POST['selected_ids']) : [];

        if ($selectedIds === []) {
            $error = 'Please select at least one item to add.';
        } else {
            match ($form) {
                'add_pages' => (static function () use ($kernel, $postedMenuId, $selectedIds): void {
                    foreach ($kernel->pages->listAllForMenuSelect() as $page) {
                        if (in_array($page['id'], $selectedIds, true)) {
                            $pageForLink = $kernel->pages->findById((int) $page['id']);
                            $kernel->menus->addMenuItem($postedMenuId, ['label' => $page['title'], 'url' => $pageForLink !== null ? page_permalink($pageForLink) : site_url($page['slug'])]);
                        }
                    }
                })(),
                'add_posts' => (static function () use ($kernel, $postedMenuId, $selectedIds): void {
                    foreach ($kernel->posts->listAllForMenuSelect() as $post) {
                        if (in_array($post['id'], $selectedIds, true)) {
                            $postForLink = $kernel->posts->findById((int) $post['id']);
                            $kernel->menus->addMenuItem($postedMenuId, ['label' => $post['title'], 'url' => $postForLink !== null ? post_permalink($postForLink) : site_url('post/' . $post['slug'])]);
                        }
                    }
                })(),
                'add_categories' => (static function () use ($kernel, $postedMenuId, $selectedIds): void {
                    foreach ($kernel->categories->listAll() as $category) {
                        if (in_array($category->id, $selectedIds, true)) {
                            $kernel->menus->addMenuItem($postedMenuId, ['label' => $category->name, 'url' => category_permalink($category)]);
                        }
                    }
                })(),
                'add_tags' => (static function () use ($kernel, $postedMenuId, $selectedIds): void {
                    foreach ($kernel->tags->listAll() as $tag) {
                        if (in_array($tag->id, $selectedIds, true)) {
                            $kernel->menus->addMenuItem($postedMenuId, ['label' => $tag->name, 'url' => tag_permalink($tag)]);
                        }
                    }
                })(),
            };

            $persistMenus();

            header('Location: ' . admin_url('appearance/menus') . '?menu_id=' . urlencode($postedMenuId) . '&saved=1');
            exit;
        }
    } elseif ($form === 'update_item') {
        $menu = $kernel->menus->menu($postedMenuId);
        $items = $menu['items'];
        $matched = false;

        foreach ($items as $index => $item) {
            if ($item['id'] === $postedItemId) {
                $parentId = trim((string) ($_POST['parentId'] ?? ''));
                $descendants = $descendantIds($items, $postedItemId);

                // Reject a parent that is this item or one of its own
                // descendants — the only cycle guard needed.
                if ($parentId === $postedItemId || in_array($parentId, $descendants, true)) {
                    $parentId = '';
                }

                $items[$index] = [
                    'id' => $item['id'],
                    'label' => trim((string) ($_POST['label'] ?? $item['label'])),
                    'url' => trim((string) ($_POST['url'] ?? $item['url'])),
                    'target' => ($_POST['target'] ?? '') === '1' ? '_blank' : '_self',
                    'cssClass' => trim((string) ($_POST['cssClass'] ?? '')),
                    'rel' => trim((string) ($_POST['rel'] ?? '')),
                    'titleAttribute' => trim((string) ($_POST['titleAttribute'] ?? '')),
                    'parentId' => $parentId,
                ];
                $matched = true;

                break;
            }
        }

        if ($matched) {
            $kernel->menus->setMenuItems($postedMenuId, $items);
            $persistMenus();

            header('Location: ' . admin_url('appearance/menus') . '?menu_id=' . urlencode($postedMenuId) . '&saved=1');
            exit;
        }

        $error = 'That menu item no longer exists.';
    } elseif ($form === 'remove_item') {
        $kernel->menus->removeMenuItem($postedMenuId, $postedItemId);
        $persistMenus();

        header('Location: ' . admin_url('appearance/menus') . '?menu_id=' . urlencode($postedMenuId) . '&removed=1');
        exit;
    } elseif ($form === 'move_item') {
        $menu = $kernel->menus->menu($postedMenuId);
        $items = $menu['items'];
        $targetIndex = null;

        foreach ($items as $index => $item) {
            if ($item['id'] === $postedItemId) {
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
            $swapPosition = $postedDirection === 'up' ? $positionAmongSiblings - 1 : $positionAmongSiblings + 1;

            if ($positionAmongSiblings !== false && $swapPosition >= 0 && $swapPosition < count($siblingIndices)) {
                $swapIndex = $siblingIndices[$swapPosition];
                [$items[$targetIndex], $items[$swapIndex]] = [$items[$swapIndex], $items[$targetIndex]];
                $kernel->menus->setMenuItems($postedMenuId, $items);
                $persistMenus();
            }
        }

        header('Location: ' . admin_url('appearance/menus') . '?menu_id=' . urlencode($postedMenuId) . '&saved=1');
        exit;
    } elseif ($form === 'reposition_item') {
        // Splices the dragged item out and back in at the target position;
        // target must share its parentId (server-side backstop for
        // sortable.js's own guard). Re-parenting stays the dropdown's job.
        $menu = $kernel->menus->menu($postedMenuId);
        $items = $menu['items'];
        $draggedIndex = null;

        foreach ($items as $index => $item) {
            if ($item['id'] === $postedItemId) {
                $draggedIndex = $index;

                break;
            }
        }

        if ($draggedIndex !== null) {
            $dragged = $items[$draggedIndex];
            $remaining = array_values(array_filter(
                $items,
                static fn (array $item): bool => $item['id'] !== $postedItemId,
            ));

            $targetIndex = null;

            foreach ($remaining as $index => $item) {
                if ($item['id'] === $postedTargetId && $item['parentId'] === $dragged['parentId']) {
                    $targetIndex = $index;

                    break;
                }
            }

            if ($targetIndex !== null) {
                $insertAt = $postedPosition === 'after' ? $targetIndex + 1 : $targetIndex;
                array_splice($remaining, $insertAt, 0, [$dragged]);
                $kernel->menus->setMenuItems($postedMenuId, $remaining);
                $persistMenus();
            }
        }

        header('Location: ' . admin_url('appearance/menus') . '?menu_id=' . urlencode($postedMenuId) . '&saved=1');
        exit;
    }
}

$allMenus = $kernel->menus->menus();
$currentMenuId = is_string($_GET['menu_id'] ?? null) ? $_GET['menu_id'] : ($redirectMenuId !== '' ? $redirectMenuId : null);

if ($currentMenuId === null || !array_key_exists($currentMenuId, $allMenus)) {
    $currentMenuId = array_key_first($allMenus);
}

$currentMenu = $currentMenuId !== null ? $allMenus[$currentMenuId] : null;
?>
<h1 class="lp-admin__title">Menus</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if (isset($_GET['removed'])): ?>
    <div class="lp-alert lp-alert--success">Menu item removed.</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="lp-alert lp-alert--success">Menu deleted.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Your Menus</h2>

    <?php if ($allMenus === []): ?>
        <p class="lp-admin__widget-placeholder">You don't have any menus yet.</p>
    <?php else: ?>
        <form method="get" action="<?= esc_url(admin_url('appearance/menus')) ?>" class="lp-menus-select-form">
            <label for="menu-select">Select a menu to edit</label>
            <select id="menu-select" name="menu_id" data-lp-auto-submit>
                <?php foreach ($allMenus as $menuId => $menu): ?>
                    <option value="<?= esc_attr($menuId) ?>" <?= $menuId === $currentMenuId ? 'selected' : '' ?>><?= esc_html($menu['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="lp-button lp-button--primary">Select</button></noscript>
        </form>
    <?php endif; ?>

    <form method="post" action="<?= esc_url(admin_url('appearance/menus')) ?>" class="lp-menus-create-form">
        <?= Csrf::field('menu_create') ?>
        <input type="hidden" name="form" value="create_menu">
        <label class="lp-visually-hidden" for="new-menu-name">New menu name</label>
        <input type="text" id="new-menu-name" name="name" placeholder="New menu name">
        <button type="submit" class="lp-button lp-button--primary">Create Menu</button>
    </form>
</section>

<?php if ($currentMenu !== null): ?>
    <section class="lp-admin__panel">
        <h2><?= esc_html($currentMenu['name']) ?></h2>

        <form method="post" action="<?= esc_url(admin_url('appearance/menus')) ?>" class="lp-menus-manage-form">
            <?= Csrf::field('menu_rename_' . $currentMenuId) ?>
            <input type="hidden" name="form" value="rename_menu">
            <input type="hidden" name="menu_id" value="<?= esc_attr($currentMenuId) ?>">
            <label class="lp-visually-hidden" for="rename-menu-name">Menu name</label>
            <input type="text" id="rename-menu-name" name="name" value="<?= esc_attr($currentMenu['name']) ?>">
            <button type="submit" class="lp-button lp-button--primary">Rename</button>
        </form>

        <form method="post" action="<?= esc_url(admin_url('appearance/menus')) ?>" class="lp-menus-manage-form">
            <?= Csrf::field('menu_duplicate_' . $currentMenuId) ?>
            <input type="hidden" name="form" value="duplicate_menu">
            <input type="hidden" name="menu_id" value="<?= esc_attr($currentMenuId) ?>">
            <label class="lp-visually-hidden" for="duplicate-menu-name">New menu's name</label>
            <input type="text" id="duplicate-menu-name" name="name" placeholder="<?= esc_attr($currentMenu['name'] . ' Copy') ?>">
            <button type="submit" class="lp-button lp-button--secondary">Duplicate</button>
        </form>

        <form method="post" action="<?= esc_url(admin_url('appearance/menus')) ?>" class="lp-menus-manage-form" data-lp-confirm="Delete this menu permanently?">
            <?= Csrf::field('menu_delete_' . $currentMenuId) ?>
            <input type="hidden" name="form" value="delete_menu">
            <input type="hidden" name="menu_id" value="<?= esc_attr($currentMenuId) ?>">
            <button type="submit" class="lp-button lp-button--danger">Delete Menu</button>
        </form>
    </section>

    <section class="lp-admin__panel lp-menus-editor">
        <div class="lp-menus-editor__add">
            <h2>Add Menu Items</h2>

            <details class="lp-menus-add-panel" open>
                <summary>Custom Link</summary>
                <form method="post" action="<?= esc_url(admin_url('appearance/menus')) ?>">
                    <?= Csrf::field('menu_add_custom_link_' . $currentMenuId) ?>
                    <input type="hidden" name="form" value="add_custom_link">
                    <input type="hidden" name="menu_id" value="<?= esc_attr($currentMenuId) ?>">
                    <p class="lp-field">
                        <label for="custom-link-url">URL</label>
                        <input type="text" id="custom-link-url" name="url" placeholder="https://example.com">
                    </p>
                    <p class="lp-field">
                        <label for="custom-link-label">Link Text</label>
                        <input type="text" id="custom-link-label" name="label">
                    </p>
                    <button type="submit" class="lp-button lp-button--primary">Add to Menu</button>
                </form>
            </details>

            <?php
            $addPanels = [
                // Pages/Categories carry a 'depth' so the checkbox list can
                // indent children; Posts/Tags have no hierarchy here.
                'add_pages' => ['label' => 'Pages', 'items' => array_map(
                    static fn (array $page): array => ['id' => $page['id'], 'label' => $page['title'], 'depth' => $page['depth']],
                    $kernel->pages->listAllForMenuSelect(),
                )],
                'add_posts' => ['label' => 'Posts', 'items' => array_map(
                    static fn (array $post): array => ['id' => $post['id'], 'label' => $post['title'], 'depth' => 0],
                    $kernel->posts->listAllForMenuSelect(),
                )],
                'add_categories' => ['label' => 'Categories', 'items' => array_map(
                    static fn (array $row): array => ['id' => $row['category']->id, 'label' => $row['category']->name, 'depth' => $row['depth']],
                    $kernel->categories->listAllForTree(),
                )],
                'add_tags' => ['label' => 'Tags', 'items' => array_map(
                    static fn ($tag): array => ['id' => $tag->id, 'label' => $tag->name, 'depth' => 0],
                    $kernel->tags->listAll(),
                )],
            ];
            ?>

            <?php foreach ($addPanels as $panelForm => $panel): ?>
                <details class="lp-menus-add-panel">
                    <summary><?= esc_html($panel['label']) ?></summary>
                    <?php if ($panel['items'] === []): ?>
                        <p class="lp-admin__widget-placeholder">None yet.</p>
                    <?php else: ?>
                        <form method="post" action="<?= esc_url(admin_url('appearance/menus')) ?>">
                            <?= Csrf::field('menu_' . $panelForm . '_' . $currentMenuId) ?>
                            <input type="hidden" name="form" value="<?= esc_attr($panelForm) ?>">
                            <input type="hidden" name="menu_id" value="<?= esc_attr($currentMenuId) ?>">
                            <ul class="lp-menus-add-panel__list">
                                <?php foreach ($panel['items'] as $option): ?>
                                    <li<?= $option['depth'] > 0 ? ' data-style-margin-left="' . ((int) $option['depth'] * 1.5) . 'rem"' : '' ?>>
                                        <label class="lp-field--checkbox">
                                            <input type="checkbox" name="selected_ids[]" value="<?= (int) $option['id'] ?>">
                                            <?= esc_html($option['label']) ?>
                                        </label>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <button type="submit" class="lp-button lp-button--primary">Add to Menu</button>
                        </form>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>
        </div>

        <div class="lp-menus-editor__structure">
            <h2>Menu Structure</h2>

            <?php if ($currentMenu['items'] === []): ?>
                <p class="lp-admin__widget-placeholder">This menu is empty. Add items from the panel.</p>
            <?php else: ?>
                <div data-lp-sortable-group="menu-<?= esc_attr($currentMenuId) ?>">
                <ul class="lp-menus-structure-list">
                    <?php foreach ($flattenForDisplay($currentMenu['items']) as $row): ?>
                        <?php
                        $item = $row['item'];
                        $descendants = $descendantIds($currentMenu['items'], $item['id']);
                        $parentOptions = array_filter(
                            $currentMenu['items'],
                            static fn (array $candidate): bool => $candidate['id'] !== $item['id'] && !in_array($candidate['id'], $descendants, true),
                        );
                        ?>
                        <li class="lp-menus-structure-list__item" data-style-margin-left="<?= (int) $row['depth'] * 1.5 ?>rem" data-lp-sortable-item data-lp-sortable-id="<?= esc_attr($item['id']) ?>" data-lp-sortable-parent="<?= esc_attr($item['parentId'] ?? '') ?>">
                            <details class="lp-widgets-list__details">
                                <summary class="lp-widgets-list__summary">
                                    <span class="lp-drag-handle" data-lp-drag-handle aria-hidden="true" title="Drag to reorder">&#10303;</span>
                                    <?= esc_html($item['label']) ?>
                                    <span class="lp-widgets-list__instance-title">&mdash; <?= esc_html($item['url']) ?></span>
                                </summary>

                                <form method="post" action="<?= esc_url(admin_url('appearance/menus')) ?>" class="lp-widgets-list__settings">
                                    <?= Csrf::field('menu_update_item_' . $item['id']) ?>
                                    <input type="hidden" name="form" value="update_item">
                                    <input type="hidden" name="menu_id" value="<?= esc_attr($currentMenuId) ?>">
                                    <input type="hidden" name="item_id" value="<?= esc_attr($item['id']) ?>">

                                    <p class="lp-field">
                                        <label for="item-label-<?= esc_attr($item['id']) ?>">Navigation Label</label>
                                        <input type="text" id="item-label-<?= esc_attr($item['id']) ?>" name="label" value="<?= esc_attr($item['label']) ?>">
                                    </p>

                                    <p class="lp-field">
                                        <label for="item-url-<?= esc_attr($item['id']) ?>">URL</label>
                                        <input type="text" id="item-url-<?= esc_attr($item['id']) ?>" name="url" value="<?= esc_attr($item['url']) ?>">
                                    </p>

                                    <p class="lp-field">
                                        <label for="item-title-<?= esc_attr($item['id']) ?>">Title Attribute</label>
                                        <input type="text" id="item-title-<?= esc_attr($item['id']) ?>" name="titleAttribute" value="<?= esc_attr($item['titleAttribute']) ?>">
                                    </p>

                                    <label class="lp-field--checkbox">
                                        <input type="checkbox" name="target" value="1" <?= $item['target'] === '_blank' ? 'checked' : '' ?>>
                                        Open in a new tab
                                    </label>

                                    <p class="lp-field">
                                        <label for="item-css-class-<?= esc_attr($item['id']) ?>">CSS Classes</label>
                                        <input type="text" id="item-css-class-<?= esc_attr($item['id']) ?>" name="cssClass" value="<?= esc_attr($item['cssClass']) ?>">
                                    </p>

                                    <p class="lp-field">
                                        <label for="item-rel-<?= esc_attr($item['id']) ?>">Link Relationship (XFN)</label>
                                        <input type="text" id="item-rel-<?= esc_attr($item['id']) ?>" name="rel" value="<?= esc_attr($item['rel']) ?>">
                                    </p>

                                    <p class="lp-field">
                                        <label for="item-parent-<?= esc_attr($item['id']) ?>">Parent Item</label>
                                        <select id="item-parent-<?= esc_attr($item['id']) ?>" name="parentId">
                                            <option value="">(none &mdash; top level)</option>
                                            <?php foreach ($parentOptions as $option): ?>
                                                <option value="<?= esc_attr($option['id']) ?>" <?= $item['parentId'] === $option['id'] ? 'selected' : '' ?>><?= esc_html($option['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </p>

                                    <button type="submit" class="lp-button lp-button--primary">Save</button>
                                </form>

                                <div class="lp-widgets-list__actions">
                                    <form method="post" action="<?= esc_url(admin_url('appearance/menus')) ?>" class="lp-admin__inline-form">
                                        <?= Csrf::field('menu_move_up_' . $item['id']) ?>
                                        <input type="hidden" name="form" value="move_item">
                                        <input type="hidden" name="menu_id" value="<?= esc_attr($currentMenuId) ?>">
                                        <input type="hidden" name="item_id" value="<?= esc_attr($item['id']) ?>">
                                        <input type="hidden" name="direction" value="up">
                                        <button type="submit" class="lp-button lp-button--secondary">Move Up</button>
                                    </form>
                                    <form method="post" action="<?= esc_url(admin_url('appearance/menus')) ?>" class="lp-admin__inline-form">
                                        <?= Csrf::field('menu_move_down_' . $item['id']) ?>
                                        <input type="hidden" name="form" value="move_item">
                                        <input type="hidden" name="menu_id" value="<?= esc_attr($currentMenuId) ?>">
                                        <input type="hidden" name="item_id" value="<?= esc_attr($item['id']) ?>">
                                        <input type="hidden" name="direction" value="down">
                                        <button type="submit" class="lp-button lp-button--secondary">Move Down</button>
                                    </form>
                                    <form method="post" action="<?= esc_url(admin_url('appearance/menus')) ?>" class="lp-admin__inline-form" data-lp-confirm="Remove this menu item?">
                                        <?= Csrf::field('menu_remove_item_' . $item['id']) ?>
                                        <input type="hidden" name="form" value="remove_item">
                                        <input type="hidden" name="menu_id" value="<?= esc_attr($currentMenuId) ?>">
                                        <input type="hidden" name="item_id" value="<?= esc_attr($item['id']) ?>">
                                        <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Remove</button>
                                    </form>
                                </div>
                            </details>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <form method="post" action="<?= esc_url(admin_url('appearance/menus')) ?>" data-lp-sortable-reposition-form>
                    <?= Csrf::field('menu_reposition_' . $currentMenuId) ?>
                    <input type="hidden" name="form" value="reposition_item">
                    <input type="hidden" name="menu_id" value="<?= esc_attr($currentMenuId) ?>">
                    <input type="hidden" name="item_id" data-lp-sortable-field="dragged_id">
                    <input type="hidden" name="target_id" data-lp-sortable-field="target_id">
                    <input type="hidden" name="position" data-lp-sortable-field="position">
                </form>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Menu Locations</h2>

    <?php if ($kernel->menus->locations() === []): ?>
        <p class="lp-admin__widget-placeholder">The active theme doesn't register any menu locations.</p>
    <?php else: ?>
        <form method="post" action="<?= esc_url(admin_url('appearance/menus')) ?>">
            <?= Csrf::field('menu_save_locations') ?>
            <input type="hidden" name="form" value="save_locations">

            <?php foreach ($kernel->menus->locations() as $locationSlug => $locationLabel): ?>
                <p class="lp-field">
                    <label for="location-<?= esc_attr($locationSlug) ?>"><?= esc_html($locationLabel) ?></label>
                    <select id="location-<?= esc_attr($locationSlug) ?>" name="location[<?= esc_attr($locationSlug) ?>]">
                        <option value="">&mdash; No menu assigned &mdash;</option>
                        <?php foreach ($allMenus as $menuId => $menu): ?>
                            <option value="<?= esc_attr($menuId) ?>" <?= $kernel->menus->menuIdForLocation($locationSlug) === $menuId ? 'selected' : '' ?>><?= esc_html($menu['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($kernel->menus->menuIdForLocation($locationSlug) === null): ?>
                        <span class="lp-field__hint">No menu assigned &mdash; this location won't render anything on the site.</span>
                    <?php endif; ?>
                </p>
            <?php endforeach; ?>

            <button type="submit" class="lp-button lp-button--primary">Save Locations</button>
        </form>
    <?php endif; ?>
</section>
