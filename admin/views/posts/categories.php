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
use LumoraPress\Models\Category;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$categoryService = $kernel->categories;
$canDeleteCategories = $currentUser->can('delete_posts');

// "Category Image" picker's grid query (featured-image-picker.js). This page has no
// controller of its own, so the query stays inline here, matching media/thumbnails.php's
// own "Default featured image" picker, which has the same no-controller shape.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'featured_image_picker_query') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    if (!$currentUser->can('edit_posts') || !Csrf::verify('featured_image_picker_query', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not permitted.']);
        exit;
    }

    $term = trim((string) ($_POST['term'] ?? ''));
    $folderId = (int) ($_POST['folder_id'] ?? 0);
    $page = max(1, (int) ($_POST['page'] ?? 1));
    $perPage = 40;

    $filters = ['type' => 'image'];

    if ($term !== '') {
        $filters['term'] = $term;
    }

    if ($folderId > 0) {
        $filters['folderIds'] = [$folderId];
    }

    $result = $kernel->media->query($filters, $perPage, ($page - 1) * $perPage);
    $thumbnailsByMediaId = $kernel->thumbnails->thumbnailsForMany(
        array_map(static fn (array $item): int => (int) $item['id'], $result['items']),
    );

    echo json_encode([
        'items' => array_map(
            static function (array $item) use ($kernel, $thumbnailsByMediaId): array {
                $sizes = [
                    'full' => [
                        'url' => $kernel->media->url($item),
                        'width' => (int) ($item['width'] ?? 0),
                        'height' => (int) ($item['height'] ?? 0),
                    ],
                ];

                foreach ($thumbnailsByMediaId[(int) $item['id']] ?? [] as $thumbnail) {
                    $sizes[(string) $thumbnail['size_name']] = [
                        'url' => $kernel->thumbnails->url($item, (string) $thumbnail['size_name']),
                        'width' => (int) $thumbnail['width'],
                        'height' => (int) $thumbnail['height'],
                    ];
                }

                return [
                    'id' => (int) $item['id'],
                    'url' => $kernel->media->url($item),
                    'name' => (string) $item['file_name'],
                    'alt' => (string) ($item['alt_text'] ?? ''),
                    'folderId' => $item['folder_id'] !== null ? (int) $item['folder_id'] : null,
                    'sizes' => $sizes,
                ];
            },
            $result['items'],
        ),
        'total' => $result['total'],
        'csrfToken' => Csrf::token('featured_image_picker_query'),
    ]);
    exit;
}

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

        // Resolution order: an uploaded file wins over the picker's own
        // selected/cleared value, mirroring PostsController::save()'s
        // featured-image resolution (minus crop — see category_image_url()'s
        // docblock for why categories don't need one).
        $imageId = (int) ($_POST['image_id'] ?? 0) > 0 ? (int) $_POST['image_id'] : null;

        if (isset($_FILES['image_upload']) && $_FILES['image_upload']['error'] !== UPLOAD_ERR_NO_FILE) {
            try {
                $uploadedImage = $kernel->media->upload($_FILES['image_upload'], $currentUser->id);
                $kernel->thumbnails->generate($uploadedImage);
                $imageId = (int) $uploadedImage['id'];
            } catch (Throwable $exception) {
                $error = 'Category image upload failed: ' . $exception->getMessage();
            }
        }

        if ($name === '') {
            $error = 'A name is required.';
        }

        if ($error === null) {
            $category = $existing === null
                ? $categoryService->create($name, $description, $parentId > 0 ? $parentId : null, $slug !== '' ? $slug : null, $imageId)
                : $categoryService->update($id, $name, $description, $parentId > 0 ? $parentId : null, $slug !== '' ? $slug : null, $imageId);

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

        // Permanent delete is only offered for categories already in the
        // Trash — Move to Trash is the only reachable path to removing
        // one from the "All" view.
        if ($existing !== null && $existing->isTrashed() && $canDeleteCategories) {
            $categoryService->delete($id);
        }

        header('Location: ' . admin_url('posts/categories') . '?status=trash&category_deleted=1');
        exit;
    } elseif ($form === 'reposition_category') {
        $draggedId = (int) ($_POST['dragged_id'] ?? 0);
        $targetId = (int) ($_POST['target_id'] ?? 0);
        $positionValue = (string) ($_POST['position'] ?? 'before');

        if (Csrf::verify('category_reposition', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null) && $draggedId > 0 && $targetId > 0) {
            $categoryService->reorder($draggedId, $targetId, $positionValue === 'after' ? 'after' : 'before');
        }

        header('Location: ' . admin_url('posts/categories'));
        exit;
    } elseif ($form === 'empty_trash' && Csrf::verify('categories_empty_trash', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        if ($canDeleteCategories) {
            $categoryService->emptyTrash();
        }

        header('Location: ' . admin_url('posts/categories') . '?status=trash&trash_emptied=1');
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

<?php if (isset($_GET['trash_emptied'])): ?>
    <div class="lp-alert lp-alert--success">Trash emptied.</div>
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
        <form method="post" action="<?= esc_url(admin_url('posts/categories')) ?>" enctype="multipart/form-data">
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

            <?php
            $currentCategoryImage = $category?->imageId !== null ? $kernel->media->find($category->imageId) : null;
            $categoryImageFolderTree = array_map(
                static fn (array $row): array => ['id' => $row['folder']->id, 'name' => $row['folder']->name, 'depth' => $row['depth']],
                $kernel->folders->listAllForTree(),
            );
            ?>
            <fieldset class="lp-field">
                <legend>Category Image</legend>
                <div
                    class="lp-featured-image-picker"
                    data-lp-featured-image-picker
                    data-picker-url="<?= esc_url(admin_url('posts/categories')) ?>"
                    data-picker-csrf="<?= esc_attr(Csrf::token('featured_image_picker_query')) ?>"
                    data-media-folders="<?= esc_attr((string) json_encode($categoryImageFolderTree)) ?>"
                >
                    <input type="hidden" name="image_id" data-picker-value value="<?= (int) ($category?->imageId ?? 0) ?>">
                    <button type="button" class="lp-button lp-button--secondary" data-picker-trigger>Choose from Media Manager&hellip;</button>
                    <button type="button" class="lp-button lp-button--link" data-picker-remove <?= $currentCategoryImage === null ? 'hidden' : '' ?>>Remove</button>
                    <span class="lp-featured-image-picker__chosen" data-picker-chosen>
                        <?php if ($currentCategoryImage !== null): ?>
                            <img class="lp-featured-image-picker__chosen-thumb" src="<?= esc_url($kernel->media->url($currentCategoryImage)) ?>" alt="">
                            <?= esc_html((string) $currentCategoryImage['file_name']) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <label for="category-image-upload">Or upload a new image</label>
                <input type="file" id="category-image-upload" name="image_upload" accept="image/*">
                <span class="lp-field__hint">Shown on this category's archive page, if the active theme supports it.</span>
            </fieldset>

            <button type="submit" class="lp-button lp-button--primary">Save Category</button>
            <a class="lp-button" href="<?= esc_url(admin_url('posts/categories')) ?>">Cancel</a>
        </form>
    </section>
<?php else: ?>
    <p><a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('posts/categories')) ?>?action=new">Add New Category</a></p>

    <?php
    $trashedCount = $categoryService->trashedCount();
    $statusLinks = ['' => 'All (' . $categoryService->count() . ')', 'trash' => 'Trash (' . $trashedCount . ')'];

    $termFilter = trim((string) ($_GET['q'] ?? ''));
    $parentFilter = (int) ($_GET['parent'] ?? 0);
    $minPostsFilter = (int) ($_GET['min_posts'] ?? 0);
    $dateFromFilter = (string) ($_GET['date_from'] ?? '');
    $dateToFilter = (string) ($_GET['date_to'] ?? '');
    $categoryFilters = [
        'term' => $termFilter,
        'parentId' => $parentFilter,
        'minPosts' => $minPostsFilter,
        'dateFrom' => $dateFromFilter,
        'dateTo' => $dateToFilter,
    ];
    $hasActiveFilter = $termFilter !== '' || $parentFilter > 0 || $minPostsFilter > 0 || $dateFromFilter !== '' || $dateToFilter !== '';

    // Tree view (with drag-and-drop ordering) only applies to the unfiltered "All" view —
    // filtering breaks hierarchical grouping (a matching child could have a non-matching
    // parent), mirroring PageService's own $isTreeView gate on all-pages.php.
    $isTreeView = !$isTrashView && !$hasActiveFilter;

    if ($isTreeView) {
        $treeRows = $categoryService->listAllForTree();
        $listedCategories = array_map(static fn (array $row): Category => $row['category'], $treeRows);

        // Which categories have at least one child — only those rows get an
        // expand/collapse toggle; a childless row gets a blank spacer instead,
        // to keep every row's title column aligned.
        $categoryIdsWithChildren = [];

        foreach ($treeRows as $treeRow) {
            if ($treeRow['category']->parentId !== null) {
                $categoryIdsWithChildren[$treeRow['category']->parentId] = true;
            }
        }
    } else {
        $rows = $isTrashView ? $categoryService->listTrashedWithPostCounts() : $categoryService->listAllWithPostCounts($categoryFilters);
        $listedCategories = array_map(static fn (array $row): Category => $row['category'], $rows);
    }
    ?>

    <p class="lp-admin__filters">
        <?php foreach ($statusLinks as $value => $label): ?>
            <a
                href="<?= esc_url(admin_url('posts/categories')) ?><?= $value !== '' ? '?status=' . esc_attr($value) : '' ?>"
                class="<?= $statusFilter === $value ? 'is-active' : '' ?>"
            ><?= esc_html($label) ?></a>
        <?php endforeach; ?>
    </p>

    <?php if (!$isTrashView): ?>
        <section class="lp-admin__panel">
            <details class="lp-admin__collapsible" <?= $termFilter !== '' || $parentFilter > 0 || $minPostsFilter > 0 || $dateFromFilter !== '' || $dateToFilter !== '' ? 'open' : '' ?>>
                <summary>Search &amp; Filter</summary>
                <div class="lp-admin__collapsible__body">
                    <form method="get" action="<?= esc_url(admin_url('posts/categories')) ?>" class="lp-admin__filter-form">
                        <p class="lp-field">
                            <label for="categories-q">Search name or slug</label>
                            <input type="text" id="categories-q" name="q" value="<?= esc_attr($termFilter) ?>">
                        </p>
                        <p class="lp-field">
                            <label for="categories-parent-filter">Parent category</label>
                            <select id="categories-parent-filter" name="parent">
                                <option value="0">All categories</option>
                                <?php foreach ($categoryService->listAllForParentPicker() as $filterCategory): ?>
                                    <option value="<?= (int) $filterCategory['id'] ?>" <?= $parentFilter === $filterCategory['id'] ? 'selected' : '' ?>><?= str_repeat('&nbsp;&nbsp;&nbsp;', $filterCategory['depth']) . esc_html($filterCategory['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p class="lp-field">
                            <label for="categories-min-posts">Minimum posts</label>
                            <input type="number" id="categories-min-posts" name="min_posts" min="0" value="<?= $minPostsFilter > 0 ? (int) $minPostsFilter : '' ?>">
                        </p>
                        <p class="lp-field">
                            <label for="categories-date-from">Created from</label>
                            <input type="date" id="categories-date-from" name="date_from" value="<?= esc_attr($dateFromFilter) ?>">
                        </p>
                        <p class="lp-field">
                            <label for="categories-date-to">Created to</label>
                            <input type="date" id="categories-date-to" name="date_to" value="<?= esc_attr($dateToFilter) ?>">
                        </p>
                        <button type="submit" class="lp-button">Filter</button>
                    </form>
                </div>
            </details>
        </section>
    <?php endif; ?>

    <section class="lp-admin__panel">
        <?php if ($isTrashView && $listedCategories !== []): ?>
            <form method="post" action="<?= esc_url(admin_url('posts/categories')) ?>" data-lp-confirm="Permanently delete every category in the Trash? This cannot be undone.">
                <?= Csrf::field('categories_empty_trash') ?>
                <input type="hidden" name="form" value="empty_trash">
                <button type="submit" class="lp-button lp-button--danger">Empty Trash</button>
            </form>
        <?php endif; ?>

        <?php if ($listedCategories === []): ?>
            <p class="lp-admin__widget-placeholder"><?= $isTrashView ? 'Trash is empty.' : 'No categories yet.' ?></p>
        <?php else: ?>
            <?php
            // The bulk-action form and (in tree view) the reposition form are siblings, not
            // nested — a <form> inside another is invalid HTML. In tree view, checkboxes use
            // form="categories-bulk-form" to submit despite living in the separate <ul> below,
            // mirroring PageService's own tree view (admin/views/pages/all-pages.php).
            ?>
            <form id="categories-bulk-form" method="post" action="<?= esc_url(admin_url('posts/categories')) ?>" <?= $isTreeView ? '' : 'data-lp-bulk-form' ?>>
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

                <?php if (!$isTreeView): ?>
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
                                    <td>
                                        <?php if ($row['postCount'] > 0): ?>
                                            <a href="<?= esc_url(admin_url('posts/all-posts')) ?>?category=<?= (int) $listedCategory->id ?>"><?= (int) $row['postCount'] ?></a>
                                        <?php else: ?>
                                            <?= (int) $row['postCount'] ?>
                                        <?php endif; ?>
                                    </td>
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
                <?php endif; ?>
            </form>

            <?php if ($isTreeView): ?>
                <div data-lp-sortable-group="categories">
                <ul class="lp-categories-tree" data-lp-tree>
                    <?php foreach ($treeRows as $treeRow): ?>
                        <?php $listedCategory = $treeRow['category']; ?>
                        <li
                            class="lp-categories-tree__item"
                            data-style-margin-left="<?= (int) $treeRow['depth'] * 1.5 ?>rem"
                            data-lp-sortable-item
                            data-lp-sortable-id="<?= (int) $listedCategory->id ?>"
                            data-lp-sortable-parent="<?= esc_attr($listedCategory->parentId !== null ? (string) $listedCategory->parentId : '') ?>"
                        >
                            <?php if (isset($categoryIdsWithChildren[$listedCategory->id])): ?>
                                <button type="button" class="lp-categories-tree__toggle" data-lp-tree-toggle aria-expanded="true" aria-label="Collapse &ldquo;<?= esc_attr($listedCategory->name) ?>&rdquo;">&#9656;</button>
                            <?php else: ?>
                                <span class="lp-categories-tree__toggle-spacer" aria-hidden="true"></span>
                            <?php endif; ?>
                            <?php if ($canDeleteCategories): ?>
                                <span class="lp-drag-handle" data-lp-drag-handle aria-hidden="true">&#10021;</span>
                                <span class="lp-categories-tree__move">
                                    <button type="button" data-lp-sortable-move="up" aria-label="Move &ldquo;<?= esc_attr($listedCategory->name) ?>&rdquo; up">&#9650;</button>
                                    <button type="button" data-lp-sortable-move="down" aria-label="Move &ldquo;<?= esc_attr($listedCategory->name) ?>&rdquo; down">&#9660;</button>
                                </span>
                                <label class="lp-visually-hidden" for="category-select-<?= (int) $listedCategory->id ?>">Select "<?= esc_html($listedCategory->name) ?>"</label>
                                <input type="checkbox" id="category-select-<?= (int) $listedCategory->id ?>" name="category_ids[]" value="<?= (int) $listedCategory->id ?>" form="categories-bulk-form">
                            <?php endif; ?>
                            <span class="lp-categories-tree__title">
                                <a href="<?= esc_url(admin_url('posts/categories')) ?>?action=edit&id=<?= (int) $listedCategory->id ?>"><?= esc_html($listedCategory->name) ?></a>
                            </span>
                            <span class="lp-categories-tree__slug"><?= esc_html($listedCategory->slug) ?></span>
                            <span class="lp-categories-tree__count">
                                <?php $treePostCount = $categoryService->postCount($listedCategory->id); ?>
                                <?php if ($treePostCount > 0): ?>
                                    <a href="<?= esc_url(admin_url('posts/all-posts')) ?>?category=<?= (int) $listedCategory->id ?>"><?= (int) $treePostCount ?></a>
                                <?php else: ?>
                                    <?= (int) $treePostCount ?>
                                <?php endif; ?>
                            </span>
                            <?php if ($canDeleteCategories): ?>
                                <?php $treeTrashFormId = 'category-trash-form-' . $listedCategory->id; ?>
                                <span class="lp-admin__inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('category_trash_' . $listedCategory->id)) ?>" form="<?= esc_attr($treeTrashFormId) ?>">
                                    <input type="hidden" name="form" value="trash" form="<?= esc_attr($treeTrashFormId) ?>">
                                    <input type="hidden" name="id" value="<?= (int) $listedCategory->id ?>" form="<?= esc_attr($treeTrashFormId) ?>">
                                    <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($treeTrashFormId) ?>" data-lp-confirm="Move this category to the Trash?">Trash</button>
                                </span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <form data-lp-sortable-reposition-form method="post" action="<?= esc_url(admin_url('posts/categories')) ?>">
                    <?= Csrf::field('category_reposition') ?>
                    <input type="hidden" name="form" value="reposition_category">
                    <input type="hidden" name="dragged_id" data-lp-sortable-field="dragged_id">
                    <input type="hidden" name="target_id" data-lp-sortable-field="target_id">
                    <input type="hidden" name="position" data-lp-sortable-field="position">
                </form>
                </div>
            <?php endif; ?>

            <?php
            // Out-of-band target forms — standalone <form>s the buttons
            // point at via form="", since a <form> can't nest inside
            // categories-bulk-form.
            if ($canDeleteCategories):
                foreach ($listedCategories as $listedCategory):
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
