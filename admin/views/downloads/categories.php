<?php

/**
 * The admin Downloads > Categories screen (LPP-011): a real list table for Downloads' own category taxonomy.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.7.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Plugins\Downloads\DownloadCategoryService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// No dedicated Controller class, matching every other Downloads admin
// screen's convention.
$downloadCategories = new DownloadCategoryService($kernel->database, (string) $kernel->config->get('table_prefix', 'lp_'));
$canDeleteCategories = $currentUser->can('upload_files');

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

    if ($form === 'save' && Csrf::verify('download_category_save', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $id = (int) ($_POST['id'] ?? 0);
        $existing = $id > 0 ? $downloadCategories->findById($id) : null;

        if ($id > 0 && $existing === null) {
            header('Location: ' . admin_url('downloads/categories') . '?error=forbidden');
            exit;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $parentId = (int) ($_POST['parent_id'] ?? 0);

        if ($name === '') {
            $error = 'A name is required.';
        } else {
            $category = $existing === null
                ? $downloadCategories->create($name, $parentId > 0 ? $parentId : null)
                : $downloadCategories->update($id, $name, $parentId > 0 ? $parentId : null);

            header('Location: ' . admin_url('downloads/categories') . '?action=edit&id=' . $category->id . '&saved=1');
            exit;
        }
    } elseif ($form === 'trash') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('download_category_trash_' . $id, $token)) {
            header('Location: ' . admin_url('downloads/categories'));
            exit;
        }

        if ($canDeleteCategories && $id > 0) {
            $downloadCategories->trash($id);
        }

        header('Location: ' . admin_url('downloads/categories') . '?trashed=1');
        exit;
    } elseif ($form === 'restore_category') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('download_category_restore_' . $id, $token)) {
            header('Location: ' . admin_url('downloads/categories'));
            exit;
        }

        if ($canDeleteCategories && $id > 0) {
            $downloadCategories->restore($id);
        }

        header('Location: ' . admin_url('downloads/categories') . '?status=trash&category_restored=1');
        exit;
    } elseif ($form === 'delete_permanently') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('download_category_delete_permanently_' . $id, $token)) {
            header('Location: ' . admin_url('downloads/categories'));
            exit;
        }

        $existing = $id > 0 ? $downloadCategories->findById($id) : null;

        // Permanent delete is only offered (and only honoured) for
        // categories already in the Trash — Move to Trash is the only
        // reachable path to actually removing a category from the "All"
        // view, the same "delete means trash first" guardrail
        // admin/views/posts/categories.php enforces.
        if ($existing !== null && $existing->isTrashed() && $canDeleteCategories) {
            $downloadCategories->delete($id);
        }

        header('Location: ' . admin_url('downloads/categories') . '?status=trash&category_deleted=1');
        exit;
    } elseif ($form === 'empty_trash' && Csrf::verify('download_categories_empty_trash', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        if ($canDeleteCategories) {
            $downloadCategories->emptyTrash();
        }

        header('Location: ' . admin_url('downloads/categories') . '?status=trash&trash_emptied=1');
        exit;
    } elseif ($form === 'bulk_action' && Csrf::verify('download_categories_bulk_action', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $bulkAction = (string) ($_POST['bulk_action'] ?? '');
        $ids = array_values(array_filter(array_map('intval', is_array($_POST['category_ids'] ?? null) ? $_POST['category_ids'] : [])));
        $mergeTargetId = (int) ($_POST['merge_target_id'] ?? 0);

        if ($canDeleteCategories) {
            foreach ($ids as $id) {
                if ($bulkAction === 'trash') {
                    $downloadCategories->trash($id);
                } elseif ($bulkAction === 'restore') {
                    $downloadCategories->restore($id);
                } elseif ($bulkAction === 'delete_permanently') {
                    $existing = $downloadCategories->findById($id);

                    if ($existing !== null && $existing->isTrashed()) {
                        $downloadCategories->delete($id);
                    }
                } elseif ($bulkAction === 'merge' && $mergeTargetId > 0 && $id !== $mergeTargetId) {
                    $downloadCategories->merge($id, $mergeTargetId);
                }
            }
        }

        header('Location: ' . admin_url('downloads/categories') . (isset($_POST['status']) ? '?status=' . urlencode((string) $_POST['status']) : ''));
        exit;
    }
}

$statusFilter = (string) ($_GET['status'] ?? '');
$isTrashView = $statusFilter === 'trash';

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : 'list';
$editingId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$editingCategory = null;

if ($action === 'edit') {
    $editingCategory = $editingId !== null ? $downloadCategories->findById($editingId) : null;

    if ($editingCategory === null || $editingCategory->isTrashed()) {
        header('Location: ' . admin_url('downloads/categories') . '?error=forbidden');
        exit;
    }
}
?>
<h1 class="lp-admin__title">Downloads Categories</h1>

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

<?php if (isset($_GET['trash_emptied'])): ?>
    <div class="lp-alert lp-alert--success">Trash emptied.</div>
<?php endif; ?>

<?php if (($_GET['error'] ?? null) === 'forbidden'): ?>
    <div class="lp-alert lp-alert--error">That category could not be found.</div>
<?php endif; ?>

<?php if ($action === 'edit' || $action === 'new'): ?>
    <?php
    $category = $editingCategory;
    $parentOptions = $downloadCategories->listAllForParentPicker($category?->id);
    ?>
    <section class="lp-admin__panel">
        <form method="post" action="<?= esc_url(admin_url('downloads/categories')) ?>">
            <?= Csrf::field('download_category_save') ?>
            <input type="hidden" name="form" value="save">
            <?php if ($category !== null): ?>
                <input type="hidden" name="id" value="<?= (int) $category->id ?>">
            <?php endif; ?>

            <p class="lp-field">
                <label for="download-category-name">Name</label>
                <input type="text" id="download-category-name" name="name" value="<?= esc_attr($category->name ?? '') ?>" required>
            </p>

            <p class="lp-field">
                <label for="download-category-parent">Parent Category</label>
                <select id="download-category-parent" name="parent_id">
                    <option value="0">(No parent)</option>
                    <?php foreach ($parentOptions as $option): ?>
                        <option value="<?= (int) $option['id'] ?>" <?= ($category?->parentId ?? 0) === $option['id'] ? 'selected' : '' ?>>
                            <?= str_repeat('&nbsp;&nbsp;&nbsp;', $option['depth']) . esc_html($option['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <button type="submit" class="lp-button lp-button--primary">Save Category</button>
            <a class="lp-button" href="<?= esc_url(admin_url('downloads/categories')) ?>">Cancel</a>
        </form>
    </section>
<?php else: ?>
    <p><a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('downloads/categories')) ?>?action=new">Add New Category</a></p>

    <?php
    $trashedCount = $downloadCategories->trashedCount();
    $statusLinks = ['' => 'All (' . $downloadCategories->count() . ')', 'trash' => 'Trash (' . $trashedCount . ')'];
    $rows = $isTrashView ? $downloadCategories->listTrashedWithDownloadCounts() : $downloadCategories->listAllWithDownloadCounts();
    ?>

    <p class="lp-admin__filters">
        <?php foreach ($statusLinks as $value => $label): ?>
            <a
                href="<?= esc_url(admin_url('downloads/categories')) ?><?= $value !== '' ? '?status=' . esc_attr($value) : '' ?>"
                class="<?= $statusFilter === $value ? 'is-active' : '' ?>"
            ><?= esc_html($label) ?></a>
        <?php endforeach; ?>
    </p>

    <section class="lp-admin__panel">
        <?php if ($isTrashView && $rows !== []): ?>
            <form method="post" action="<?= esc_url(admin_url('downloads/categories')) ?>" data-lp-confirm="Permanently delete every category in the Trash? This cannot be undone.">
                <?= Csrf::field('download_categories_empty_trash') ?>
                <input type="hidden" name="form" value="empty_trash">
                <button type="submit" class="lp-button lp-button--danger">Empty Trash</button>
            </form>
        <?php endif; ?>

        <?php if ($rows === []): ?>
            <p class="lp-admin__widget-placeholder"><?= $isTrashView ? 'Trash is empty.' : 'No categories yet.' ?></p>
        <?php else: ?>
            <form id="download-categories-bulk-form" method="post" action="<?= esc_url(admin_url('downloads/categories')) ?>" data-lp-bulk-form>
                <?= Csrf::field('download_categories_bulk_action') ?>
                <input type="hidden" name="form" value="bulk_action">
                <?php if ($statusFilter !== ''): ?>
                    <input type="hidden" name="status" value="<?= esc_attr($statusFilter) ?>">
                <?php endif; ?>

                <?php if ($canDeleteCategories): ?>
                    <p class="lp-admin__bulk-actions">
                        <label class="lp-visually-hidden" for="download-categories-bulk-action">Bulk action</label>
                        <select id="download-categories-bulk-action" name="bulk_action">
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
                            <label class="lp-visually-hidden" for="download-categories-merge-target">Merge target</label>
                            <select id="download-categories-merge-target" name="merge_target_id">
                                <option value="0">(select a category to merge into)</option>
                                <?php foreach ($downloadCategories->listAll() as $mergeTargetOption): ?>
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
                                    <label class="lp-visually-hidden" for="download-categories-select-all">Select all</label>
                                    <input type="checkbox" id="download-categories-select-all" data-lp-select-all="category_ids[]" data-lp-select-all-scope="table">
                                </th>
                            <?php endif; ?>
                            <th scope="col">ID</th>
                            <th scope="col">Name</th>
                            <th scope="col">Parent</th>
                            <th scope="col">Shortcode</th>
                            <th scope="col">Downloads</th>
                            <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $listedCategory = $row['category'];
                            $parentCategory = $listedCategory->parentId !== null ? $downloadCategories->findById($listedCategory->parentId) : null;
                            ?>
                            <tr>
                                <?php if ($canDeleteCategories): ?>
                                    <td>
                                        <label class="lp-visually-hidden" for="download-category-select-<?= (int) $listedCategory->id ?>">Select "<?= esc_html($listedCategory->name) ?>"</label>
                                        <input type="checkbox" id="download-category-select-<?= (int) $listedCategory->id ?>" name="category_ids[]" value="<?= (int) $listedCategory->id ?>">
                                    </td>
                                <?php endif; ?>
                                <td><?= (int) $listedCategory->id ?></td>
                                <td>
                                    <?php if ($isTrashView): ?>
                                        <?= esc_html($listedCategory->name) ?>
                                    <?php else: ?>
                                        <a href="<?= esc_url(admin_url('downloads/categories')) ?>?action=edit&id=<?= (int) $listedCategory->id ?>"><?= esc_html($listedCategory->name) ?></a>
                                    <?php endif; ?>
                                </td>
                                <td><?= $parentCategory !== null ? esc_html($parentCategory->name) : '—' ?></td>
                                <td><code>[lumora_downloads category_id="<?= (int) $listedCategory->id ?>"]</code></td>
                                <td><?= (int) $row['downloadCount'] ?></td>
                                <td class="lp-admin__row-actions">
                                    <?php if ($canDeleteCategories): ?>
                                        <?php if ($isTrashView): ?>
                                            <?php $restoreFormId = 'download-category-restore-form-' . $listedCategory->id; ?>
                                            <span class="lp-admin__inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('download_category_restore_' . $listedCategory->id)) ?>" form="<?= esc_attr($restoreFormId) ?>">
                                                <input type="hidden" name="form" value="restore_category" form="<?= esc_attr($restoreFormId) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $listedCategory->id ?>" form="<?= esc_attr($restoreFormId) ?>">
                                                <button type="submit" class="lp-button lp-button--link" form="<?= esc_attr($restoreFormId) ?>">Restore</button>
                                            </span>
                                            <?php $deletePermFormId = 'download-category-delete-permanently-form-' . $listedCategory->id; ?>
                                            <span class="lp-admin__inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('download_category_delete_permanently_' . $listedCategory->id)) ?>" form="<?= esc_attr($deletePermFormId) ?>">
                                                <input type="hidden" name="form" value="delete_permanently" form="<?= esc_attr($deletePermFormId) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $listedCategory->id ?>" form="<?= esc_attr($deletePermFormId) ?>">
                                                <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($deletePermFormId) ?>" data-lp-confirm="Permanently delete this category? Child categories will be kept but become top-level, and its downloads will become Uncategorized. This cannot be undone.">Delete Permanently</button>
                                            </span>
                                        <?php else: ?>
                                            <?php $trashFormId = 'download-category-trash-form-' . $listedCategory->id; ?>
                                            <span class="lp-admin__inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('download_category_trash_' . $listedCategory->id)) ?>" form="<?= esc_attr($trashFormId) ?>">
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
            // Out-of-band target forms — a <form> can't nest inside
            // download-categories-bulk-form.
            if ($canDeleteCategories):
                foreach ($rows as $row):
                    $listedCategory = $row['category'];
                    ?>
                    <?php if ($isTrashView): ?>
                        <form id="download-category-restore-form-<?= (int) $listedCategory->id ?>" method="post" action="<?= esc_url(admin_url('downloads/categories')) ?>"></form>
                        <form id="download-category-delete-permanently-form-<?= (int) $listedCategory->id ?>" method="post" action="<?= esc_url(admin_url('downloads/categories')) ?>"></form>
                    <?php else: ?>
                        <form id="download-category-trash-form-<?= (int) $listedCategory->id ?>" method="post" action="<?= esc_url(admin_url('downloads/categories')) ?>"></form>
                    <?php endif; ?>
                    <?php
                endforeach;
            endif;
            ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
