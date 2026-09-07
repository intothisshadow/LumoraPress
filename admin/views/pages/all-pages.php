<?php

/**
 * The admin Pages list screen.
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

use LumoraPress\Controllers\Admin\PagesController;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\Page;
use LumoraPress\Models\PageStatus;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$pageService = $kernel->pages;
$canPublish = $currentUser->can('publish_posts');
$canDeletePages = $currentUser->can('delete_posts');
$canEditOthersPages = $currentUser->can('edit_others_posts');

$error = null;

/**
 * An author/contributor may only touch their own pages unless they hold
 * edit_others_posts (Administrator/Editor) — Pages reuse the Posts
 * capabilities, since no dedicated page capabilities exist yet. Kept
 * here for the display-only checks below; PagesController has its own
 * copy backing every POST action.
 */
$canEditPage = static fn (Page $page): bool => $canEditOthersPages || $page->authorId === $currentUser->id;

// POST handling lives in PagesController; this view dispatches to it and
// turns the AdminActionResult into a redirect or $error string.
// Quick Edit is a JSON sub-action handled separately since it never
// redirects — the row stays on the list screen and updates in place via JS.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if ($form === 'quick_edit') {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json');

        (new PagesController($kernel->pages, $kernel->revisions, $kernel->media, $kernel->thumbnails))
            ->quickEdit($_POST, $currentUser->id, $canPublish, $canEditOthersPages, $csrfToken);
        exit;
    }

    if ($form !== '') {
        $controller = new PagesController($kernel->pages, $kernel->revisions, $kernel->media, $kernel->thumbnails);

        $result = match ($form) {
            'trash' => $controller->trash($_POST, $currentUser->id, $canDeletePages, $canEditOthersPages, $csrfToken),
            'restore_page' => $controller->restorePage($_POST, $currentUser->id, $canDeletePages, $canEditOthersPages, $csrfToken),
            'delete_permanently' => $controller->deletePermanently($_POST, $currentUser->id, $canDeletePages, $canEditOthersPages, $csrfToken),
            'empty_trash' => $controller->emptyTrash($currentUser->id, $canDeletePages, $canEditOthersPages, $csrfToken),
            'duplicate' => $controller->duplicate($_POST, $currentUser->id, $canEditOthersPages, $csrfToken),
            'reposition_page' => $controller->repositionPage($_POST, $currentUser->id, $canEditOthersPages, $csrfToken),
            'bulk_action' => $controller->bulkAction($_POST, $currentUser->id, $canPublish, $canDeletePages, $canEditOthersPages, $csrfToken),
            default => null,
        };

        if ($result !== null) {
            if ($result->redirectUrl !== null) {
                redirect($result->redirectUrl);
            }

            $error = $result->errorMessage;
        }
    }
}
?>
<h1 class="lp-admin__title">Pages</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['trashed'])): ?>
    <div class="lp-alert lp-alert--success">Page moved to Trash.</div>
<?php endif; ?>

<?php if (isset($_GET['page_restored'])): ?>
    <div class="lp-alert lp-alert--success">Page restored as a draft.</div>
<?php endif; ?>

<?php if (isset($_GET['page_deleted'])): ?>
    <div class="lp-alert lp-alert--success">Page permanently deleted.</div>
<?php endif; ?>

<?php if (isset($_GET['trash_emptied'])): ?>
    <div class="lp-alert lp-alert--success">Trash emptied.</div>
<?php endif; ?>

<?php if (($_GET['error'] ?? null) === 'forbidden'): ?>
    <div class="lp-alert lp-alert--error">You do not have permission to edit that page.</div>
<?php endif; ?>

<p><a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('pages/new')) ?>">Add New Page</a></p>

<?php
$page = max(1, (int) ($_GET['paged'] ?? 1));
$statusFilter = PageStatus::tryFrom((string) ($_GET['status'] ?? ''));
$isTrashView = $statusFilter === PageStatus::Trashed;

$termFilter = trim((string) ($_GET['q'] ?? ''));
$authorFilter = (int) ($_GET['author'] ?? 0);
$parentFilter = (int) ($_GET['parent'] ?? 0);
$dateFromFilter = (string) ($_GET['date_from'] ?? '');
$dateToFilter = (string) ($_GET['date_to'] ?? '');
$hasActiveFilter = $termFilter !== '' || $authorFilter > 0 || $parentFilter > 0 || $dateFromFilter !== '' || $dateToFilter !== '';
$listFilters = [
    'term' => $termFilter,
    'authorId' => $authorFilter,
    'parentId' => $parentFilter,
    'dateFrom' => $dateFromFilter,
    'dateTo' => $dateToFilter,
];

$orderBy = (string) ($_GET['orderby'] ?? 'date');
$orderDir = (string) ($_GET['order'] ?? 'desc');

// Tree view (with drag-and-drop ordering) only applies to the
// unfiltered "All" tab — filtering by status (or by any search/filter
// field below) breaks hierarchical grouping (a child could be
// Published while its parent is a Draft, or match a search term its
// parent doesn't), and the Trash tab keeps the flat/paginated list
// every other status filter already uses.
$isTreeView = $statusFilter === null && !$hasActiveFilter;

// Trash is excluded from "All" (see PageService::paginateForAdmin()'s
// docblock), so the "All" tab's own count is every non-Trashed status
// summed rather than a simple "no filter" count.
$statusCounts = [];

foreach (PageStatus::cases() as $statusCase) {
    $statusCounts[$statusCase->value] = $pageService->countByStatus($statusCase);
}

$allCount = $statusCounts[PageStatus::Draft->value] + $statusCounts[PageStatus::PendingReview->value]
    + $statusCounts[PageStatus::Published->value] + $statusCounts[PageStatus::Scheduled->value];
$statusLinks = ['' => 'All (' . $allCount . ')', ...array_combine(
    array_map(static fn (PageStatus $status): string => $status->value, PageStatus::cases()),
    array_map(static fn (PageStatus $status): string => $status->label() . ' (' . $statusCounts[$status->value] . ')', PageStatus::cases()),
)];

$allPagesForParentFilter = $pageService->listAllForParentPicker();
$allUsersForFilter = $kernel->users->listAll();

if ($isTreeView) {
    $treeRows = $pageService->listAllForTree();
    $listedPages = array_map(static fn (array $row): Page => $row['page'], $treeRows);
} else {
    $pagination = $pageService->paginateForAdmin($page, statusFilter: $statusFilter, filters: $listFilters, orderBy: $orderBy, orderDir: $orderDir);
    $listedPages = $pagination['pages'];
}

// Sortable column headers — mirrors the Downloads admin list's identical
// $sortLink pattern (admin/views/downloads/all-downloads.php).
$sortLink = static function (string $column) use ($orderBy, $orderDir, $statusFilter, $termFilter, $authorFilter, $parentFilter, $dateFromFilter, $dateToFilter): string {
    $nextDir = $orderBy === $column && $orderDir === 'asc' ? 'desc' : 'asc';
    $query = ['orderby' => $column, 'order' => $nextDir];

    if ($statusFilter !== null) {
        $query['status'] = $statusFilter->value;
    }

    if ($termFilter !== '') {
        $query['q'] = $termFilter;
    }

    if ($authorFilter > 0) {
        $query['author'] = $authorFilter;
    }

    if ($parentFilter > 0) {
        $query['parent'] = $parentFilter;
    }

    if ($dateFromFilter !== '') {
        $query['date_from'] = $dateFromFilter;
    }

    if ($dateToFilter !== '') {
        $query['date_to'] = $dateToFilter;
    }

    return admin_url('pages/all-pages') . '?' . http_build_query($query);
};
?>

<p class="lp-admin__filters">
    <?php foreach ($statusLinks as $value => $label): ?>
        <a
            href="<?= esc_url(admin_url('pages/all-pages')) ?><?= $value !== '' ? '?status=' . esc_attr($value) : '' ?>"
            class="<?= ($statusFilter?->value ?? '') === $value ? 'is-active' : '' ?>"
        ><?= esc_html($label) ?></a>
    <?php endforeach; ?>
</p>

<section class="lp-admin__panel">
    <details class="lp-admin__collapsible" <?= $hasActiveFilter ? 'open' : '' ?>>
        <summary>Search &amp; Filter</summary>
        <div class="lp-admin__collapsible__body">
            <form method="get" action="<?= esc_url(admin_url('pages/all-pages')) ?>" class="lp-admin__filter-form">
                <?php if ($statusFilter !== null): ?>
                    <input type="hidden" name="status" value="<?= esc_attr($statusFilter->value) ?>">
                <?php endif; ?>
                <p class="lp-field">
                    <label for="pages-q">Search title &amp; content</label>
                    <input type="text" id="pages-q" name="q" value="<?= esc_attr($termFilter) ?>">
                </p>
                <p class="lp-field">
                    <label for="pages-author-filter">Author</label>
                    <select id="pages-author-filter" name="author">
                        <option value="0">All authors</option>
                        <?php foreach ($allUsersForFilter as $filterUser): ?>
                            <option value="<?= (int) $filterUser->id ?>" <?= $authorFilter === $filterUser->id ? 'selected' : '' ?>><?= esc_html($filterUser->displayName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p class="lp-field">
                    <label for="pages-parent-filter">Parent page</label>
                    <select id="pages-parent-filter" name="parent">
                        <option value="0">All pages</option>
                        <?php foreach ($allPagesForParentFilter as $parentFilterOption): ?>
                            <option value="<?= (int) $parentFilterOption['id'] ?>" <?= $parentFilter === $parentFilterOption['id'] ? 'selected' : '' ?>><?= str_repeat('&nbsp;&nbsp;&nbsp;', $parentFilterOption['depth']) . esc_html($parentFilterOption['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p class="lp-field">
                    <label for="pages-date-from">Date from</label>
                    <input type="date" id="pages-date-from" name="date_from" value="<?= esc_attr($dateFromFilter) ?>">
                </p>
                <p class="lp-field">
                    <label for="pages-date-to">Date to</label>
                    <input type="date" id="pages-date-to" name="date_to" value="<?= esc_attr($dateToFilter) ?>">
                </p>
                <button type="submit" class="lp-button">Filter</button>
                <?php if ($hasActiveFilter): ?>
                    <a class="lp-button lp-button--secondary" href="<?= esc_url(admin_url('pages/all-pages')) ?><?= $statusFilter !== null ? '?status=' . esc_attr($statusFilter->value) : '' ?>">Clear</a>
                <?php endif; ?>
            </form>
        </div>
    </details>
</section>

<section class="lp-admin__panel">
    <?php if ($isTrashView && $statusCounts[PageStatus::Trashed->value] > 0): ?>
        <form method="post" action="<?= esc_url(admin_url('pages/all-pages')) ?>" data-lp-confirm="Permanently delete every page in the Trash? This cannot be undone.">
            <?= Csrf::field('pages_empty_trash') ?>
            <input type="hidden" name="form" value="empty_trash">
            <button type="submit" class="lp-button lp-button--danger">Empty Trash</button>
        </form>
    <?php endif; ?>

    <?php if ($listedPages === []): ?>
        <p class="lp-admin__widget-placeholder"><?= $isTrashView ? 'Trash is empty.' : 'No pages yet.' ?></p>
    <?php else: ?>
        <?php
        // The bulk-action form and (in tree view) the reposition form
        // are siblings, not nested — a <form> inside another is invalid
        // HTML. In tree view, checkboxes use form="pages-bulk-form" to
        // submit despite living in the separate <ul> below.
        ?>
        <form id="pages-bulk-form" method="post" action="<?= esc_url(admin_url('pages/all-pages')) ?>" <?= $isTreeView ? '' : 'data-lp-bulk-form' ?>>
            <?= Csrf::field('pages_bulk_action') ?>
            <input type="hidden" name="form" value="bulk_action">
            <?php if ($statusFilter !== null): ?>
                <input type="hidden" name="status" value="<?= esc_attr($statusFilter->value) ?>">
            <?php endif; ?>

            <p class="lp-admin__bulk-actions">
                <label class="lp-visually-hidden" for="pages-bulk-action">Bulk action</label>
                <select id="pages-bulk-action" name="bulk_action">
                    <option value="">Bulk actions</option>
                    <?php if ($isTrashView): ?>
                        <option value="restore">Restore</option>
                        <?php if ($canDeletePages): ?><option value="delete_permanently">Delete Permanently</option><?php endif; ?>
                    <?php else: ?>
                        <?php if ($canPublish): ?>
                            <option value="publish">Publish</option>
                            <option value="draft">Mark as Draft</option>
                            <option value="set_public">Set Public</option>
                            <option value="set_private">Set Private</option>
                        <?php endif; ?>
                        <?php if ($canDeletePages): ?><option value="trash">Move to Trash</option><?php endif; ?>
                        <?php if ($canEditOthersPages): ?><option value="change_parent">Change parent to&hellip;</option><?php endif; ?>
                        <?php if ($canEditOthersPages): ?><option value="change_author">Change author to&hellip;</option><?php endif; ?>
                    <?php endif; ?>
                </select>
                <?php if ($canEditOthersPages): ?>
                    <select name="target_parent_id">
                        <option value="0">(No parent)</option>
                        <?php foreach ($allPagesForParentFilter as $parentFilterOption): ?>
                            <option value="<?= (int) $parentFilterOption['id'] ?>"><?= str_repeat('&nbsp;&nbsp;&nbsp;', $parentFilterOption['depth']) . esc_html($parentFilterOption['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="target_author_id">
                        <?php foreach ($allUsersForFilter as $bulkAuthorOption): ?>
                            <option value="<?= (int) $bulkAuthorOption->id ?>"><?= esc_html($bulkAuthorOption->displayName) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <button type="submit" class="lp-button lp-button--secondary">Apply</button>
            </p>

            <?php if (!$isTreeView): ?>
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th scope="col">
                                <label class="lp-visually-hidden" for="pages-select-all">Select all</label>
                                <input type="checkbox" id="pages-select-all" data-lp-select-all="page_ids[]" data-lp-select-all-scope="table">
                            </th>
                            <th scope="col"><a href="<?= esc_url($sortLink('title')) ?>">Title</a></th>
                            <th scope="col">Status</th>
                            <th scope="col"><a href="<?= esc_url($sortLink('created')) ?>">Created</a></th>
                            <th scope="col"><a href="<?= esc_url($sortLink('date')) ?>">Published</a></th>
                            <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($listedPages as $listedPage): ?>
                            <tr id="page-row-<?= (int) $listedPage->id ?>">
                                <td>
                                    <?php if ($canEditPage($listedPage)): ?>
                                        <label class="lp-visually-hidden" for="page-select-<?= (int) $listedPage->id ?>">Select "<?= esc_html($listedPage->title) ?>"</label>
                                        <input type="checkbox" id="page-select-<?= (int) $listedPage->id ?>" name="page_ids[]" value="<?= (int) $listedPage->id ?>">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$isTrashView && $canEditPage($listedPage)): ?>
                                        <a href="<?= esc_url(admin_url('pages/new')) ?>?id=<?= (int) $listedPage->id ?>" data-lp-quick-edit-title-link><?= esc_html($listedPage->title) ?></a>
                                    <?php else: ?>
                                        <?= esc_html($listedPage->title) ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="lp-status-badge lp-status-badge--<?= esc_attr($listedPage->status->value) ?>" data-lp-quick-edit-status-badge>
                                        <?= esc_html($listedPage->status->label()) ?>
                                    </span>
                                </td>
                                <td><?= esc_html($listedPage->createdAt->format('M j, Y')) ?></td>
                                <td><?= $listedPage->publishedAt !== null ? esc_html($listedPage->publishedAt->format('M j, Y')) : '&mdash;' ?></td>
                                <td class="lp-admin__row-actions">
                                    <?php if ($canEditPage($listedPage)): ?>
                                        <?php if ($isTrashView): ?>
                                            <?php $restoreFormId = 'page-restore-form-' . $listedPage->id; ?>
                                            <span class="lp-admin__inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('page_restore_page_' . $listedPage->id)) ?>" form="<?= esc_attr($restoreFormId) ?>">
                                                <input type="hidden" name="form" value="restore_page" form="<?= esc_attr($restoreFormId) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $listedPage->id ?>" form="<?= esc_attr($restoreFormId) ?>">
                                                <button type="submit" class="lp-button lp-button--link" form="<?= esc_attr($restoreFormId) ?>">Restore</button>
                                            </span>
                                            <?php if ($canDeletePages): ?>
                                                <?php $deletePermFormId = 'page-delete-permanently-form-' . $listedPage->id; ?>
                                                <span class="lp-admin__inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('page_delete_permanently_' . $listedPage->id)) ?>" form="<?= esc_attr($deletePermFormId) ?>">
                                                    <input type="hidden" name="form" value="delete_permanently" form="<?= esc_attr($deletePermFormId) ?>">
                                                    <input type="hidden" name="id" value="<?= (int) $listedPage->id ?>" form="<?= esc_attr($deletePermFormId) ?>">
                                                    <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($deletePermFormId) ?>" data-lp-confirm="Permanently delete this page? This cannot be undone.">Delete Permanently</button>
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if ($listedPage->status === PageStatus::Published): ?>
                                                <a class="lp-button lp-button--link lp-button--link--view" href="<?= esc_url(page_permalink($listedPage)) ?>" target="_blank" rel="noopener">View</a>
                                            <?php endif; ?>
                                            <button type="button" class="lp-button lp-button--link" data-lp-quick-edit-trigger data-lp-quick-edit-show="page-quick-edit-<?= (int) $listedPage->id ?>" data-lp-quick-edit-hide="page-row-<?= (int) $listedPage->id ?>">Quick Edit</button>
                                            <?php $duplicateFormId = 'page-duplicate-form-' . $listedPage->id; ?>
                                            <span class="lp-admin__inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('page_duplicate_' . $listedPage->id)) ?>" form="<?= esc_attr($duplicateFormId) ?>">
                                                <input type="hidden" name="form" value="duplicate" form="<?= esc_attr($duplicateFormId) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $listedPage->id ?>" form="<?= esc_attr($duplicateFormId) ?>">
                                                <button type="submit" class="lp-button lp-button--link" form="<?= esc_attr($duplicateFormId) ?>">Duplicate</button>
                                            </span>
                                            <?php if ($canDeletePages): ?>
                                                <?php $trashFormId = 'page-trash-form-' . $listedPage->id; ?>
                                                <span class="lp-admin__inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('page_trash_' . $listedPage->id)) ?>" form="<?= esc_attr($trashFormId) ?>">
                                                    <input type="hidden" name="form" value="trash" form="<?= esc_attr($trashFormId) ?>">
                                                    <input type="hidden" name="id" value="<?= (int) $listedPage->id ?>" form="<?= esc_attr($trashFormId) ?>">
                                                    <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($trashFormId) ?>" data-lp-confirm="Move this page to the Trash?">Trash</button>
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!$isTrashView && $canEditPage($listedPage)): ?>
                                <?php $quickEditFormId = 'page-quick-edit-form-' . $listedPage->id; ?>
                                <tr id="page-quick-edit-<?= (int) $listedPage->id ?>" class="lp-quick-edit-row" hidden>
                                    <td colspan="6">
                                        <input type="hidden" name="id" value="<?= (int) $listedPage->id ?>" form="<?= esc_attr($quickEditFormId) ?>">
                                        <div class="lp-quick-edit-fields">
                                            <p class="lp-field">
                                                <label for="page-quick-edit-title-<?= (int) $listedPage->id ?>">Title</label>
                                                <input type="text" id="page-quick-edit-title-<?= (int) $listedPage->id ?>" name="title" value="<?= esc_attr($listedPage->title) ?>" required form="<?= esc_attr($quickEditFormId) ?>">
                                            </p>
                                            <p class="lp-field">
                                                <label for="page-quick-edit-slug-<?= (int) $listedPage->id ?>">Slug</label>
                                                <input type="text" id="page-quick-edit-slug-<?= (int) $listedPage->id ?>" name="slug" value="<?= esc_attr($listedPage->slug) ?>" form="<?= esc_attr($quickEditFormId) ?>">
                                            </p>
                                            <?php if ($canEditOthersPages): ?>
                                                <p class="lp-field">
                                                    <label for="page-quick-edit-parent-<?= (int) $listedPage->id ?>">Parent</label>
                                                    <select id="page-quick-edit-parent-<?= (int) $listedPage->id ?>" name="parent_id" form="<?= esc_attr($quickEditFormId) ?>">
                                                        <option value="0">(No parent)</option>
                                                        <?php foreach ($allPagesForParentFilter as $parentOption): ?>
                                                            <option value="<?= (int) $parentOption['id'] ?>" <?= $listedPage->parentId === $parentOption['id'] ? 'selected' : '' ?>><?= str_repeat('&nbsp;&nbsp;&nbsp;', $parentOption['depth']) . esc_html($parentOption['title']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </p>
                                            <?php endif; ?>
                                            <?php if ($canPublish): ?>
                                                <p class="lp-field">
                                                    <label for="page-quick-edit-status-<?= (int) $listedPage->id ?>">Status</label>
                                                    <select id="page-quick-edit-status-<?= (int) $listedPage->id ?>" name="status" form="<?= esc_attr($quickEditFormId) ?>">
                                                        <option value="<?= esc_attr(PageStatus::Draft->value) ?>" <?= $listedPage->status === PageStatus::Draft ? 'selected' : '' ?>><?= esc_html(PageStatus::Draft->label()) ?></option>
                                                        <option value="<?= esc_attr(PageStatus::Published->value) ?>" <?= $listedPage->status === PageStatus::Published ? 'selected' : '' ?>><?= esc_html(PageStatus::Published->label()) ?></option>
                                                    </select>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <p class="lp-quick-edit-error" data-lp-quick-edit-error hidden></p>
                                        <div class="lp-quick-edit-actions">
                                            <button type="submit" class="lp-button lp-button--primary" form="<?= esc_attr($quickEditFormId) ?>">Update</button>
                                            <button type="button" class="lp-button" data-lp-quick-edit-cancel data-lp-quick-edit-show="page-row-<?= (int) $listedPage->id ?>" data-lp-quick-edit-hide="page-quick-edit-<?= (int) $listedPage->id ?>">Cancel</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </form>

        <?php if ($isTreeView): ?>
            <div data-lp-sortable-group="pages">
            <ul class="lp-pages-tree">
                <?php foreach ($treeRows as $treeRow): ?>
                    <?php $listedPage = $treeRow['page']; ?>
                    <li
                        class="lp-pages-tree__item"
                        data-style-margin-left="<?= (int) $treeRow['depth'] * 1.5 ?>rem"
                        data-lp-sortable-item
                        data-lp-sortable-id="<?= (int) $listedPage->id ?>"
                        data-lp-sortable-parent="<?= esc_attr($listedPage->parentId !== null ? (string) $listedPage->parentId : '') ?>"
                    >
                        <?php if ($canEditPage($listedPage)): ?>
                            <span class="lp-drag-handle" data-lp-drag-handle aria-hidden="true">&#10021;</span>
                            <span class="lp-pages-tree__move">
                                <button type="button" data-lp-sortable-move="up" aria-label="Move &ldquo;<?= esc_attr($listedPage->title) ?>&rdquo; up">&#9650;</button>
                                <button type="button" data-lp-sortable-move="down" aria-label="Move &ldquo;<?= esc_attr($listedPage->title) ?>&rdquo; down">&#9660;</button>
                            </span>
                            <label class="lp-visually-hidden" for="page-select-<?= (int) $listedPage->id ?>">Select "<?= esc_html($listedPage->title) ?>"</label>
                            <input type="checkbox" id="page-select-<?= (int) $listedPage->id ?>" name="page_ids[]" value="<?= (int) $listedPage->id ?>" form="pages-bulk-form">
                        <?php endif; ?>
                        <span class="lp-pages-tree__title">
                            <?php if ($canEditPage($listedPage)): ?>
                                <a href="<?= esc_url(admin_url('pages/new')) ?>?id=<?= (int) $listedPage->id ?>"><?= esc_html($listedPage->title) ?></a>
                            <?php else: ?>
                                <?= esc_html($listedPage->title) ?>
                            <?php endif; ?>
                        </span>
                        <span class="lp-status-badge lp-status-badge--<?= esc_attr($listedPage->status->value) ?>">
                            <?= esc_html($listedPage->status->label()) ?>
                        </span>
                        <span class="lp-pages-tree__date"><?= esc_html(($listedPage->publishedAt ?? $listedPage->updatedAt)->format('M j, Y')) ?></span>
                        <?php if ($canEditPage($listedPage)): ?>
                            <?php if ($listedPage->status === PageStatus::Published): ?>
                                <a class="lp-button lp-button--link lp-button--link--view" href="<?= esc_url(page_permalink($listedPage)) ?>" target="_blank" rel="noopener">View</a>
                            <?php endif; ?>
                            <?php $treeDuplicateFormId = 'page-duplicate-form-' . $listedPage->id; ?>
                            <span class="lp-admin__inline-form">
                                <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('page_duplicate_' . $listedPage->id)) ?>" form="<?= esc_attr($treeDuplicateFormId) ?>">
                                <input type="hidden" name="form" value="duplicate" form="<?= esc_attr($treeDuplicateFormId) ?>">
                                <input type="hidden" name="id" value="<?= (int) $listedPage->id ?>" form="<?= esc_attr($treeDuplicateFormId) ?>">
                                <button type="submit" class="lp-button lp-button--link" form="<?= esc_attr($treeDuplicateFormId) ?>">Duplicate</button>
                            </span>
                            <?php if ($canDeletePages): ?>
                                <?php $treeTrashFormId = 'page-trash-form-' . $listedPage->id; ?>
                                <span class="lp-admin__inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('page_trash_' . $listedPage->id)) ?>" form="<?= esc_attr($treeTrashFormId) ?>">
                                    <input type="hidden" name="form" value="trash" form="<?= esc_attr($treeTrashFormId) ?>">
                                    <input type="hidden" name="id" value="<?= (int) $listedPage->id ?>" form="<?= esc_attr($treeTrashFormId) ?>">
                                    <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($treeTrashFormId) ?>" data-lp-confirm="Move this page to the Trash?">Trash</button>
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form data-lp-sortable-reposition-form method="post" action="<?= esc_url(admin_url('pages/all-pages')) ?>">
                <?= Csrf::field('page_reposition') ?>
                <input type="hidden" name="form" value="reposition_page">
                <input type="hidden" name="dragged_id" data-lp-sortable-field="dragged_id">
                <input type="hidden" name="target_id" data-lp-sortable-field="target_id">
                <input type="hidden" name="position" data-lp-sortable-field="position">
            </form>
            </div>
        <?php endif; ?>

        <?php
        // Out-of-band target forms for each row's action buttons —
        // standalone <form>s the buttons point at via form="", instead of
        // being descendants of pages-bulk-form or the reposition form.
        foreach ($listedPages as $listedPage):
            if (!$canEditPage($listedPage)) {
                continue;
            }

            if ($isTrashView):
                if (!$canDeletePages) {
                    continue;
                }
                ?>
                <form id="page-restore-form-<?= (int) $listedPage->id ?>" method="post" action="<?= esc_url(admin_url('pages/all-pages')) ?>"></form>
                <form id="page-delete-permanently-form-<?= (int) $listedPage->id ?>" method="post" action="<?= esc_url(admin_url('pages/all-pages')) ?>"></form>
                <?php
            else:
                ?>
                <form id="page-duplicate-form-<?= (int) $listedPage->id ?>" method="post" action="<?= esc_url(admin_url('pages/all-pages')) ?>"></form>
                <?php if ($canDeletePages): ?>
                    <form id="page-trash-form-<?= (int) $listedPage->id ?>" method="post" action="<?= esc_url(admin_url('pages/all-pages')) ?>"></form>
                <?php endif; ?>
                <?php if (!$isTreeView): ?>
                    <?php
                    // Quick Edit's form carries real visible fields (via
                    // form="" on each row input), submitted as JSON by
                    // quick-edit.js rather than a redirect.
                    ?>
                    <form
                        id="page-quick-edit-form-<?= (int) $listedPage->id ?>"
                        method="post"
                        action="<?= esc_url(admin_url('pages/all-pages')) ?>"
                        data-lp-quick-edit-form
                        data-lp-quick-edit-url="<?= esc_url(admin_url('pages/all-pages')) ?>"
                        data-lp-quick-edit-show="page-row-<?= (int) $listedPage->id ?>"
                        data-lp-quick-edit-hide="page-quick-edit-<?= (int) $listedPage->id ?>"
                    >
                        <?= Csrf::field('page_quick_edit_' . $listedPage->id) ?>
                        <input type="hidden" name="form" value="quick_edit">
                    </form>
                <?php endif; ?>
                <?php
            endif;
        endforeach;
        ?>

        <?php if (!$isTreeView): ?>
            <?php render_pagination($pagination, 'Pages pagination'); ?>
        <?php endif; ?>
    <?php endif; ?>
</section>
