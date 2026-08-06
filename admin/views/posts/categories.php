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
    } elseif ($form === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('category_delete_' . $id, $token)) {
            header('Location: ' . admin_url('posts/categories'));
            exit;
        }

        if ($canDeleteCategories && $id > 0) {
            $categoryService->delete($id);
        }

        header('Location: ' . admin_url('posts/categories'));
        exit;
    }
}

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : 'list';
$editingId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$editingCategory = null;

if ($action === 'edit') {
    $editingCategory = $editingId !== null ? $categoryService->findById($editingId) : null;

    if ($editingCategory === null) {
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

<?php if (($_GET['error'] ?? null) === 'forbidden'): ?>
    <div class="lp-alert lp-alert--error">That category could not be found.</div>
<?php endif; ?>

<?php if ($action === 'edit' || $action === 'new'): ?>
    <?php
    $category = $editingCategory;
    $parentOptions = $categoryService->listAllForParentSelect($category?->id);
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
                            <?= esc_html($option['name']) ?>
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

    <?php $rows = $categoryService->listAllWithPostCounts(); ?>

    <section class="lp-admin__panel">
        <?php if ($rows === []): ?>
            <p class="lp-admin__widget-placeholder">No categories yet.</p>
        <?php else: ?>
            <table class="lp-table">
                <thead>
                    <tr>
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
                            <td>
                                <a href="<?= esc_url(admin_url('posts/categories')) ?>?action=edit&id=<?= (int) $listedCategory->id ?>"><?= esc_html($listedCategory->name) ?></a>
                            </td>
                            <td><?= esc_html($listedCategory->slug) ?></td>
                            <td><?= $parentCategory !== null ? esc_html($parentCategory->name) : '—' ?></td>
                            <td><?= (int) $row['postCount'] ?></td>
                            <td>
                                <?php if ($canDeleteCategories): ?>
                                    <form method="post" action="<?= esc_url(admin_url('posts/categories')) ?>" data-lp-confirm="Delete this category permanently? Child categories will be kept but become top-level.">
                                        <?= Csrf::field('category_delete_' . $listedCategory->id) ?>
                                        <input type="hidden" name="form" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $listedCategory->id ?>">
                                        <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
<?php endif; ?>
