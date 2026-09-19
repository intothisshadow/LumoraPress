<?php

/**
 * The admin Link Directory > All Links screen (LPP-026): a real list table with status tabs, category filter, bulk actions, and Duplicate/Trash row actions.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.10.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Plugins\LinkDirectory\LinkDirectoryCategoryService;
use LumoraPress\Plugins\LinkDirectory\LinkService;
use LumoraPress\Plugins\LinkDirectory\LinkStatus;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Only reachable while the Link Directory plugin is active, so LinkService's
// class is guaranteed to already be loaded.
$linkCategories = new LinkDirectoryCategoryService($kernel->database, (string) $kernel->config->get('table_prefix', 'lp_'));
$links = new LinkService($kernel->database, (string) $kernel->config->get('table_prefix', 'lp_'));

$error = null;

// No dedicated Controller class — this plugin handles its own POST bodies
// inline via LinkService calls, the same convention Downloads' own
// All Downloads screen uses.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
    $id = (int) ($_POST['id'] ?? 0);

    if ($form === 'trash' && Csrf::verify('link_trash_' . $id, $csrfToken)) {
        $links->trash($id);
        redirect(admin_url('link-directory/all-links') . '?trashed=1');
    } elseif ($form === 'restore' && Csrf::verify('link_restore_' . $id, $csrfToken)) {
        $links->restore($id);
        redirect(admin_url('link-directory/all-links') . '?status=trashed&restored=1');
    } elseif ($form === 'delete_permanently' && Csrf::verify('link_delete_permanently_' . $id, $csrfToken)) {
        $links->delete($id);
        redirect(admin_url('link-directory/all-links') . '?status=trashed&deleted=1');
    } elseif ($form === 'duplicate' && Csrf::verify('link_duplicate_' . $id, $csrfToken)) {
        $links->duplicate($id);
        redirect(admin_url('link-directory/all-links') . '?duplicated=1');
    } elseif ($form === 'bulk_action' && Csrf::verify('links_bulk_action', $csrfToken)) {
        $bulkAction = (string) ($_POST['bulk_action'] ?? '');
        $ids = array_map('intval', (array) ($_POST['link_ids'] ?? []));
        $statusPassthrough = (string) ($_POST['status'] ?? '');

        foreach ($ids as $bulkId) {
            match ($bulkAction) {
                'trash' => $links->trash($bulkId),
                'restore' => $links->restore($bulkId),
                'delete_permanently' => $links->delete($bulkId),
                default => null,
            };
        }

        redirect(admin_url('link-directory/all-links') . '?bulk_done=1' . ($statusPassthrough !== '' ? '&status=' . urlencode($statusPassthrough) : ''));
    }
}
?>
<h1 class="lp-admin__title">Link Directory</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Link saved.</div>
<?php endif; ?>

<?php if (isset($_GET['trashed'])): ?>
    <div class="lp-alert lp-alert--success">Link moved to Trash.</div>
<?php endif; ?>

<?php if (isset($_GET['restored'])): ?>
    <div class="lp-alert lp-alert--success">Link restored.</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="lp-alert lp-alert--success">Link permanently deleted.</div>
<?php endif; ?>

<?php if (isset($_GET['duplicated'])): ?>
    <div class="lp-alert lp-alert--success">Link duplicated.</div>
<?php endif; ?>

<?php if (isset($_GET['bulk_done'])): ?>
    <div class="lp-alert lp-alert--success">Bulk action applied.</div>
<?php endif; ?>

<p><a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('link-directory/add-new')) ?>">Add New Link</a></p>

<?php
$page = max(1, (int) ($_GET['paged'] ?? 1));
$statusFilter = ((string) ($_GET['status'] ?? '')) === 'trashed' ? LinkStatus::Trashed : null;
$isTrashView = $statusFilter === LinkStatus::Trashed;

$categoryFilter = (int) ($_GET['category_id'] ?? 0);
$termFilter = trim((string) ($_GET['q'] ?? ''));
$orderBy = (string) ($_GET['orderby'] ?? 'title');
$orderDir = (string) ($_GET['order'] ?? 'asc');

$listFilters = ['term' => $termFilter, 'categoryId' => $categoryFilter];
$pagination = $links->paginateForAdmin($page, statusFilter: $statusFilter, filters: $listFilters, orderBy: $orderBy, orderDir: $orderDir);

$statusCounts = [
    LinkStatus::Live->value => $links->countByStatus(LinkStatus::Live),
    LinkStatus::Trashed->value => $links->countByStatus(LinkStatus::Trashed),
];
$statusLinks = [
    '' => 'Live (' . $statusCounts[LinkStatus::Live->value] . ')',
    'trashed' => 'Trash (' . $statusCounts[LinkStatus::Trashed->value] . ')',
];

$allCategories = $linkCategories->listAll();
$categoriesById = [];

foreach ($allCategories as $category) {
    $categoriesById[$category->id] = $category;
}

$sortLink = static function (string $column) use ($orderBy, $orderDir, $statusFilter, $categoryFilter): string {
    $nextDir = $orderBy === $column && $orderDir === 'asc' ? 'desc' : 'asc';
    $query = ['orderby' => $column, 'order' => $nextDir];

    if ($statusFilter !== null) {
        $query['status'] = $statusFilter->value;
    }

    if ($categoryFilter > 0) {
        $query['category_id'] = $categoryFilter;
    }

    return admin_url('link-directory/all-links') . '?' . http_build_query($query);
};
?>

<p class="lp-admin__filters">
    <?php foreach ($statusLinks as $value => $label): ?>
        <a
            href="<?= esc_url(admin_url('link-directory/all-links')) ?><?= $value !== '' ? '?status=' . esc_attr($value) : '' ?>"
            class="<?= ($statusFilter?->value ?? '') === $value ? 'is-active' : '' ?>"
        ><?= esc_html($label) ?></a>
    <?php endforeach; ?>
</p>

<section class="lp-admin__panel">
    <form method="get" action="<?= esc_url(admin_url('link-directory/all-links')) ?>" class="lp-admin__filter-form">
        <?php if ($statusFilter !== null): ?>
            <input type="hidden" name="status" value="<?= esc_attr($statusFilter->value) ?>">
        <?php endif; ?>
        <p class="lp-field">
            <label for="link-directory-category-filter">Category</label>
            <select id="link-directory-category-filter" name="category_id">
                <option value="0">All categories</option>
                <?php foreach ($allCategories as $filterCategory): ?>
                    <option value="<?= (int) $filterCategory->id ?>" <?= $categoryFilter === $filterCategory->id ? 'selected' : '' ?>><?= esc_html($filterCategory->name) ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="lp-field">
            <label for="link-directory-q">Search title/URL</label>
            <input type="text" id="link-directory-q" name="q" value="<?= esc_attr($termFilter) ?>">
        </p>
        <button type="submit" class="lp-button">Filter</button>
    </form>
</section>

<section class="lp-admin__panel">
    <?php if ($pagination['links'] === []): ?>
        <p class="lp-admin__widget-placeholder"><?= $isTrashView ? 'Trash is empty.' : 'No links yet.' ?></p>
    <?php else: ?>
        <form method="post" action="<?= esc_url(admin_url('link-directory/all-links')) ?>" data-lp-bulk-form>
            <?= Csrf::field('links_bulk_action') ?>
            <input type="hidden" name="form" value="bulk_action">
            <?php if ($statusFilter !== null): ?>
                <input type="hidden" name="status" value="<?= esc_attr($statusFilter->value) ?>">
            <?php endif; ?>

            <p class="lp-admin__bulk-actions">
                <label class="lp-visually-hidden" for="links-bulk-action">Bulk action</label>
                <select id="links-bulk-action" name="bulk_action">
                    <option value="">Bulk actions</option>
                    <?php if ($isTrashView): ?>
                        <option value="restore">Restore</option>
                        <option value="delete_permanently">Delete Permanently</option>
                    <?php else: ?>
                        <option value="trash">Move to Trash</option>
                    <?php endif; ?>
                </select>
                <button type="submit" class="lp-button lp-button--secondary">Apply</button>
            </p>

            <table class="lp-table">
                <thead>
                    <tr>
                        <th scope="col">
                            <label class="lp-visually-hidden" for="links-select-all">Select all</label>
                            <input type="checkbox" id="links-select-all" data-lp-select-all="link_ids[]" data-lp-select-all-scope="table">
                        </th>
                        <th scope="col"><a href="<?= esc_url($sortLink('id')) ?>">ID</a></th>
                        <th scope="col"><a href="<?= esc_url($sortLink('title')) ?>">Title</a></th>
                        <th scope="col">URL</th>
                        <th scope="col">Category</th>
                        <th scope="col">Status</th>
                        <th scope="col"><a href="<?= esc_url($sortLink('date')) ?>">Date</a></th>
                        <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagination['links'] as $listedLink): ?>
                        <tr>
                            <td>
                                <label class="lp-visually-hidden" for="link-select-<?= (int) $listedLink->id ?>">Select "<?= esc_html($listedLink->title) ?>"</label>
                                <input type="checkbox" id="link-select-<?= (int) $listedLink->id ?>" name="link_ids[]" value="<?= (int) $listedLink->id ?>">
                            </td>
                            <td><?= (int) $listedLink->id ?></td>
                            <td>
                                <?php if (!$isTrashView): ?>
                                    <a href="<?= esc_url(admin_url('link-directory/add-new')) ?>?id=<?= (int) $listedLink->id ?>"><?= esc_html($listedLink->title) ?></a>
                                <?php else: ?>
                                    <?= esc_html($listedLink->title) ?>
                                <?php endif; ?>
                            </td>
                            <td><a href="<?= esc_url($listedLink->url) ?>" target="_blank" rel="noopener noreferrer"><?= esc_html($listedLink->url) ?></a></td>
                            <td><?= esc_html($listedLink->categoryId !== null ? ($categoriesById[$listedLink->categoryId]?->name ?? 'Uncategorized') : 'Uncategorized') ?></td>
                            <td>
                                <span class="lp-status-badge lp-status-badge--<?= esc_attr($listedLink->status()->value) ?>">
                                    <?= esc_html($listedLink->status() === LinkStatus::Live ? 'Live' : 'Trashed') ?>
                                </span>
                            </td>
                            <td><?= esc_html($listedLink->createdAt->format('M j, Y')) ?></td>
                            <td class="lp-admin__row-actions">
                                <?php if ($isTrashView): ?>
                                    <?php $restoreFormId = 'link-restore-form-' . $listedLink->id; ?>
                                    <span class="lp-admin__inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('link_restore_' . $listedLink->id)) ?>" form="<?= esc_attr($restoreFormId) ?>">
                                        <input type="hidden" name="form" value="restore" form="<?= esc_attr($restoreFormId) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $listedLink->id ?>" form="<?= esc_attr($restoreFormId) ?>">
                                        <button type="submit" class="lp-button lp-button--link" form="<?= esc_attr($restoreFormId) ?>">Restore</button>
                                    </span>
                                    <?php $deletePermFormId = 'link-delete-permanently-form-' . $listedLink->id; ?>
                                    <span class="lp-admin__inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('link_delete_permanently_' . $listedLink->id)) ?>" form="<?= esc_attr($deletePermFormId) ?>">
                                        <input type="hidden" name="form" value="delete_permanently" form="<?= esc_attr($deletePermFormId) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $listedLink->id ?>" form="<?= esc_attr($deletePermFormId) ?>">
                                        <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($deletePermFormId) ?>" data-lp-confirm="Permanently delete this link? This cannot be undone.">Delete Permanently</button>
                                    </span>
                                <?php else: ?>
                                    <?php $duplicateFormId = 'link-duplicate-form-' . $listedLink->id; ?>
                                    <span class="lp-admin__inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('link_duplicate_' . $listedLink->id)) ?>" form="<?= esc_attr($duplicateFormId) ?>">
                                        <input type="hidden" name="form" value="duplicate" form="<?= esc_attr($duplicateFormId) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $listedLink->id ?>" form="<?= esc_attr($duplicateFormId) ?>">
                                        <button type="submit" class="lp-button lp-button--link" form="<?= esc_attr($duplicateFormId) ?>">Duplicate</button>
                                    </span>
                                    <?php $trashFormId = 'link-trash-form-' . $listedLink->id; ?>
                                    <span class="lp-admin__inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('link_trash_' . $listedLink->id)) ?>" form="<?= esc_attr($trashFormId) ?>">
                                        <input type="hidden" name="form" value="trash" form="<?= esc_attr($trashFormId) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $listedLink->id ?>" form="<?= esc_attr($trashFormId) ?>">
                                        <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($trashFormId) ?>" data-lp-confirm="Move this link to the Trash?">Trash</button>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>

        <?php
        // Out-of-band target forms for each row action button — a nested
        // <form> is invalid HTML and would merge into the bulk-action form.
        foreach ($pagination['links'] as $listedLink):
            if ($isTrashView):
                ?>
                <form id="link-restore-form-<?= (int) $listedLink->id ?>" method="post" action="<?= esc_url(admin_url('link-directory/all-links')) ?>"></form>
                <form id="link-delete-permanently-form-<?= (int) $listedLink->id ?>" method="post" action="<?= esc_url(admin_url('link-directory/all-links')) ?>"></form>
                <?php
            else:
                ?>
                <form id="link-duplicate-form-<?= (int) $listedLink->id ?>" method="post" action="<?= esc_url(admin_url('link-directory/all-links')) ?>"></form>
                <form id="link-trash-form-<?= (int) $listedLink->id ?>" method="post" action="<?= esc_url(admin_url('link-directory/all-links')) ?>"></form>
                <?php
            endif;
        endforeach;
        ?>

        <?php render_pagination($pagination, 'Links pagination'); ?>
    <?php endif; ?>
</section>
