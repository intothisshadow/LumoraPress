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

use LumoraPress\Controllers\Admin\MenusController;
use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/**
 * Every id in $items that is $itemId or a descendant of it — excluded
 * from that item's own "Parent" <select> so an item can never become its
 * own ancestor. Duplicated from MenusController::descendantIds() (private
 * there) since this view needs it purely for rendering each item's own
 * "Parent" option list, not for the save-time cycle guard the controller
 * already applies.
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

// Many forms render on this same page load. POST handling lives in
// MenusController, including the per-menu/item/direction CSRF action
// scoping (see its own docblock) — this view just dispatches to it and turns
// the result into a redirect or an $error string.
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$error = null;
$postedMenuId = trim((string) ($_POST['menu_id'] ?? ''));
$csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

if ($form !== '') {
    $controller = new MenusController($kernel->menus, $kernel->config, $kernel->pages, $kernel->posts, $kernel->categories, $kernel->tags);

    $result = match ($form) {
        'create_menu' => $controller->createMenu($_POST, $csrfToken),
        'rename_menu' => $controller->renameMenu($_POST, $csrfToken),
        'duplicate_menu' => $controller->duplicateMenu($_POST, $csrfToken),
        'delete_menu' => $controller->deleteMenu($_POST, $csrfToken),
        'add_custom_link' => $controller->addCustomLink($_POST, $csrfToken),
        'add_pages' => $controller->addPages($_POST, $csrfToken),
        'add_posts' => $controller->addPosts($_POST, $csrfToken),
        'add_categories' => $controller->addCategories($_POST, $csrfToken),
        'add_tags' => $controller->addTags($_POST, $csrfToken),
        'update_item' => $controller->updateItem($_POST, $csrfToken),
        'remove_item' => $controller->removeItem($_POST, $csrfToken),
        'move_item' => $controller->moveItem($_POST, $csrfToken),
        'reposition_item' => $controller->repositionItem($_POST, $csrfToken),
        'save_locations' => $controller->saveLocations($_POST, $csrfToken),
        default => null,
    };

    if ($result !== null) {
        if ($result->redirectUrl !== null) {
            redirect($result->redirectUrl);
        }

        $error = $result->errorMessage;
    }
}

$allMenus = $kernel->menus->menus();
$currentMenuId = is_string($_GET['menu_id'] ?? null) ? $_GET['menu_id'] : ($postedMenuId !== '' ? $postedMenuId : null);

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
