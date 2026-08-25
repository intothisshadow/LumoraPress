<?php

/**
 * The admin Categories management screen.
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

$categoryService = $kernel->categories;
$canDeleteCategories = $currentUser->can('delete_posts');

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

    if ($form === 'save' && Csrf::verify('category_save', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $id = (int) ($_POST['id'] ?? 0);
        $existing = $id > 0 ? $categoryService->findById($id) : null;

        if ($id > 0 && $existing === null) {
            header('Location: ' . admin_url('posts/categories') . '?error=forbidden');
            exit;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $parentId = (int) ($_POST['parent_id'] ?? 0);

        if ($name === '') {
            $error = 'A name is required.';
        } else {
            $category = $existing === null
                ? $categoryService->create($name, $description, $parentId > 0 ? $parentId : null, $slug !== '' ? $slug : null)
                : $categoryService->update($id, $name, $description, $parentId > 0 ? $parentId : null, $slug !== '' ? $slug : null);

            header('Location: ' . admin_url('posts/categories') . '?action=edit&id=' . $category->id . '&saved=1');
            exit;
        }
    } elseif ($form === 'trash') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('category_trash_' . $id, $token)) {
            header('Location: ' . admin_url('posts/categories'));
            exit;
        }

        if ($canDeleteCategories && $id > 0) {
            $categoryService->trash($id);
        }

        header('Location: ' . admin_url('posts/categories') . '?trashed=1');
        exit;
    } elseif ($form === 'restore_category') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('category_restore_' . $id, $token)) {
            header('Location: ' . admin_url('posts/categories'));
            exit;
        }

        if ($canDeleteCategories && $id > 0) {
            $categoryService->restore($id);
        }

        header('Location: ' . admin_url('posts/categories') . '?status=trash&category_restored=1');
        exit;
    } elseif ($form === 'delete_permanently') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('category_delete_permanently_' . $id, $token)) {
            header('Location: ' . admin_url('posts/categories'));
            exit;
        }

        $existing = $id > 0 ? $categoryService->findById($id) : null;

        // Permanent delete is only offered (and only honoured) for
        // categories already in the Trash — Move to Trash is the only
        // reachable path to actually removing a category from the "All"
        // view, the same "delete means trash first" guardrail
        // admin/views/pages/all-pages.php enforces.
        if ($existing !== null && $existing->isTrashed() && $canDeleteCategories) {
            $categoryService->delete($id);
        }

        header('Location: ' . admin_url('posts/categories') . '?status=trash&category_deleted=1');
        exit;
    } elseif ($form === 'bulk_action' && Csrf::verify('categories_bulk_action', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $bulkAction = (string) ($_POST['bulk_action'] ?? '');
        $ids = array_values(array_filter(array_map('intval', is_array($_POST['category_ids'] ?? null) ? $_POST['category_ids'] : [])));
        $mergeTargetId = (int) ($_POST['merge_target_id'] ?? 0);

        if ($canDeleteCategories) {
            foreach ($ids as $id) {
                if ($bulkAction === 'trash') {
                    $categoryService->trash($id);
                } elseif ($bulkAction === 'restore') {
                    $categoryService->restore($id);
                } elseif ($bulkAction === 'delete_permanently') {
                    $existing = $categoryService->findById($id);

                    if ($existing !== null && $existing->isTrashed()) {
                        $categoryService->delete($id);
                    }
                } elseif ($bulkAction === 'merge' && $mergeTargetId > 0 && $id !== $mergeTargetId) {
                    $categoryService->merge($id, $mergeTargetId);
                }
            }
        }

        header('Location: ' . admin_url('posts/categories') . (isset($_POST['status']) ? '?status=' . urlencode((string) $_POST['status']) : ''));
        exit;
    }
}

$statusFilter = (string) ($_GET['status'] ?? '');
$isTrashView = $statusFilter === 'trash';

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : 'list';
$editingId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$editingCategory = null;

if ($action === 'edit') {
    $editingCategory = $editingId !== null ? $categoryService->findById($editingId) : null;

    if ($editingCategory === null || $editingCategory->isTrashed()) {
        header('Location: ' . admin_url('posts/categories') . '?error=forbidden');
        exit;
    }
}
?>
<h1 class="lp-admin__title">Categories</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Category saved.</div>
<?php endif; ?>

<?php if (isset($_GET['trashed'])): ?>
    <div class="lp-alert lp-alert--success">Category moved to Trash.</div>
<?php endif; ?>

<?php if (isset($_GET['category_restored'])): ?>
    <div class="lp-alert lp-alert--success">Category restored.</div>
<?php endif; ?>

<?php if (isset($_GET['category_deleted'])): ?>
    <div class="lp-alert lp-alert--success">Category permanently deleted.</div>
<?php endif; ?>

<?php if (($_GET['error'] ?? null) === 'forbidden'): ?>
    <div class="lp-alert lp-alert--error">That category could not be found.</div>
<?php endif; ?>

<?php if ($action === 'edit' || $action === 'new'): ?>
    <?php
    $category = $editingCategory;
    $parentOptions = $categoryService->listAllForParentPicker($category?->id);
    ?>
    <section class="lp-admin__panel">
        <form method="post" action="<?= esc_url(admin_url('posts/categories')) ?>">
            <?= Csrf::field('category_save') ?>
            <input type="hidden" name="form" value="save">
            <?php if ($category !== null): ?>
                <input type="hidden" name="id" value="<?= (int) $category->id ?>">
            <?php endif; ?>

            <p class="lp-field">
                <label for="category-name">Name</label>
                <input type="text" id="category-name" name="name" value="<?= esc_attr($category->name ?? '') ?>" required>
            </p>

            <p class="lp-field">
                <label for="category-slug">Slug</label>
                <input type="text" id="category-slug" name="slug" value="<?= esc_attr($category->slug ?? '') ?>">
                <span class="lp-field__hint">Leave blank to generate one automatically from the name.</span>
            </p>

            <p class="lp-field">
                <label for="category-description">Description</label>
                <textarea id="category-description" name="description" rows="3"><?= esc_html($category->description ?? '') ?></textarea>
            </p>

            <p class="lp-field">
                <label for="category-parent">Parent Category</label>
                <select id="category-parent" name="parent_id">
                    <option value="0">(No parent)</option>
                    <?php foreach ($parentOptions as $option): ?>
                        <option value="<?= (int) $option['id'] ?>" <?= ($category?->parentId ?? 0) === $option['id'] ? 'selected' : '' ?>>
                            <?= str_repeat('&nbsp;&nbsp;&nbsp;', $option['depth']) . esc_html($option['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <button type="submit" class="lp-button lp-button--primary">Save Category</button>
            <a class="lp-button" href="<?= esc_url(admin_url('posts/categories')) ?>">Cancel</a>
        </form>
    </section>
<?php else: ?>
    <p><a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('posts/categories')) ?>?action=new">Add New Category</a></p>

    <?php
    $trashedCount = $categoryService->trashedCount();
    $statusLinks = ['' => 'All (' . $categoryService->count() . ')', 'trash' => 'Trash (' . $trashedCount . ')'];
    $rows = $isTrashView ? $categoryService->listTrashedWithPostCounts() : $categoryService->listAllWithPostCounts();
    ?>

    <p class="lp-admin__filters">
        <?php foreach ($statusLinks as $value => $label): ?>
            <a
                href="<?= esc_url(admin_url('posts/categories')) ?><?= $value !== '' ? '?status=' . esc_attr($value) : '' ?>"
                class="<?= $statusFilter === $value ? 'is-active' : '' ?>"
            ><?= esc_html($label) ?></a>
        <?php endforeach; ?>
    </p>

    <section class="lp-admin__panel">
        <?php if ($rows === []): ?>
            <p class="lp-admin__widget-placeholder"><?= $isTrashView ? 'Trash is empty.' : 'No categories yet.' ?></p>
        <?php else: ?>
            <form id="categories-bulk-form" method="post" action="<?= esc_url(admin_url('posts/categories')) ?>" data-lp-bulk-form>
                <?= Csrf::field('categories_bulk_action') ?>
                <input type="hidden" name="form" value="bulk_action">
                <?php if ($statusFilter !== ''): ?>
                    <input type="hidden" name="status" value="<?= esc_attr($statusFilter) ?>">
                <?php endif; ?>

                <?php if ($canDeleteCategories): ?>
                    <p class="lp-admin__bulk-actions">
                        <label class="lp-visually-hidden" for="categories-bulk-action">Bulk action</label>
                        <select id="categories-bulk-action" name="bulk_action">
                            <option value="">Bulk actions</option>
                            <?php if ($isTrashView): ?>
                                <option value="restore">Restore</option>
                                <option value="delete_permanently">Delete Permanently</option>
                            <?php else: ?>
                                <option value="trash">Move to Trash</option>
                                <option value="merge">Merge into&hellip;</option>
                            <?php endif; ?>
                        </select>
                        <?php if (!$isTrashView): ?>
                            <label class="lp-visually-hidden" for="categories-merge-target">Merge target</label>
                            <select id="categories-merge-target" name="merge_target_id">
                                <option value="0">(select a category to merge into)</option>
                                <?php foreach ($categoryService->listAll() as $mergeTargetOption): ?>
                                    <option value="<?= (int) $mergeTargetOption->id ?>"><?= esc_html($mergeTargetOption->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <button type="submit" class="lp-button lp-button--secondary">Apply</button>
                    </p>
                <?php endif; ?>

                <table class="lp-table">
                    <thead>
                        <tr>
                            <?php if ($canDeleteCategories): ?>
                                <th scope="col">
                                    <label class="lp-visually-hidden" for="categories-select-all">Select all</label>
                                    <input type="checkbox" id="categories-select-all" data-lp-select-all="category_ids[]" data-lp-select-all-scope="table">
                                </th>
                            <?php endif; ?>
                            <th scope="col">Name</th>
                            <th scope="col">Slug</th>
                            <th scope="col">Parent</th>
                            <th scope="col">Posts</th>
                            <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $listedCategory = $row['category'];
                            $parentCategory = $listedCategory->parentId !== null ? $categoryService->findById($listedCategory->parentId) : null;
                            ?>
                            <tr>
                                <?php if ($canDeleteCategories): ?>
                                    <td>
                                        <label class="lp-visually-hidden" for="category-select-<?= (int) $listedCategory->id ?>">Select "<?= esc_html($listedCategory->name) ?>"</label>
                                        <input type="checkbox" id="category-select-<?= (int) $listedCategory->id ?>" name="category_ids[]" value="<?= (int) $listedCategory->id ?>">
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <?php if ($isTrashView): ?>
                                        <?= esc_html($listedCategory->name) ?>
                                    <?php else: ?>
                                        <a href="<?= esc_url(admin_url('posts/categories')) ?>?action=edit&id=<?= (int) $listedCategory->id ?>"><?= esc_html($listedCategory->name) ?></a>
                                    <?php endif; ?>
                                </td>
                                <td><?= esc_html($listedCategory->slug) ?></td>
                                <td><?= $parentCategory !== null ? esc_html($parentCategory->name) : '—' ?></td>
                                <td><?= (int) $row['postCount'] ?></td>
                                <td class="lp-admin__row-actions">
                                    <?php if ($canDeleteCategories): ?>
                                        <?php if ($isTrashView): ?>
                                            <?php $restoreFormId = 'category-restore-form-' . $listedCategory->id; ?>
                                            <span class="lp-admin__inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('category_restore_' . $listedCategory->id)) ?>" form="<?= esc_attr($restoreFormId) ?>">
                                                <input type="hidden" name="form" value="restore_category" form="<?= esc_attr($restoreFormId) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $listedCategory->id ?>" form="<?= esc_attr($restoreFormId) ?>">
                                                <button type="submit" class="lp-button lp-button--link" form="<?= esc_attr($restoreFormId) ?>">Restore</button>
                                            </span>
                                            <?php $deletePermFormId = 'category-delete-permanently-form-' . $listedCategory->id; ?>
                                            <span class="lp-admin__inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('category_delete_permanently_' . $listedCategory->id)) ?>" form="<?= esc_attr($deletePermFormId) ?>">
                                                <input type="hidden" name="form" value="delete_permanently" form="<?= esc_attr($deletePermFormId) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $listedCategory->id ?>" form="<?= esc_attr($deletePermFormId) ?>">
                                                <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($deletePermFormId) ?>" data-lp-confirm="Permanently delete this category? Child categories will be kept but become top-level. This cannot be undone.">Delete Permanently</button>
                                            </span>
                                        <?php else: ?>
                                            <?php $trashFormId = 'category-trash-form-' . $listedCategory->id; ?>
                                            <span class="lp-admin__inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('category_trash_' . $listedCategory->id)) ?>" form="<?= esc_attr($trashFormId) ?>">
                                                <input type="hidden" name="form" value="trash" form="<?= esc_attr($trashFormId) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $listedCategory->id ?>" form="<?= esc_attr($trashFormId) ?>">
                                                <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($trashFormId) ?>" data-lp-confirm="Move this category to the Trash?">Trash</button>
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <?php
            /*
             * Out-of-band target forms for each row's Trash/Restore/Delete
             * Permanently button above — standalone, empty <form>s the
             * buttons point at via the HTML `form=""` attribute, since a
             * <form> can't nest inside categories-bulk-form (same LP-068
             * nested-form fix admin/views/pages/all-pages.php uses).
             */
            if ($canDeleteCategories):
                foreach ($rows as $row):
                    $listedCategory = $row['category'];
                    ?>
                    <?php if ($isTrashView): ?>
                        <form id="category-restore-form-<?= (int) $listedCategory->id ?>" method="post" action="<?= esc_url(admin_url('posts/categories')) ?>"></form>
                        <form id="category-delete-permanently-form-<?= (int) $listedCategory->id ?>" method="post" action="<?= esc_url(admin_url('posts/categories')) ?>"></form>
                    <?php else: ?>
                        <form id="category-trash-form-<?= (int) $listedCategory->id ?>" method="post" action="<?= esc_url(admin_url('posts/categories')) ?>"></form>
                    <?php endif; ?>
                    <?php
                endforeach;
            endif;
            ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
