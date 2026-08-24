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

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\Page;
use LumoraPress\Models\PageStatus;
use LumoraPress\Models\PageVisibility;
use LumoraPress\Models\RevisionableType;

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
 * capabilities, since no dedicated page capabilities exist yet.
 */
$canEditPage = static fn (Page $page): bool => $canEditOthersPages || $page->authorId === $currentUser->id;

/*
 * Quick Edit (LP-009) — a JSON sub-action, same pattern as
 * admin/views/pages/new.php's editor_upload/convert_content: the row
 * stays on the list screen and updates in place via JS rather than
 * navigating to the full editor. Handled before the CSRF-gated
 * redirect-based dispatch below since it never redirects.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'quick_edit') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    $id = (int) ($_POST['id'] ?? 0);
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if (!Csrf::verify('page_quick_edit_' . $id, $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Your session expired. Reload the page and try again.']);
        exit;
    }

    $existing = $id > 0 ? $pageService->findById($id) : null;

    if ($existing === null || !$canEditPage($existing)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not permitted.']);
        exit;
    }

    $title = trim((string) ($_POST['title'] ?? ''));

    if ($title === '') {
        http_response_code(422);
        echo json_encode(['error' => 'A title is required.']);
        exit;
    }

    $slug = trim((string) ($_POST['slug'] ?? ''));
    $parentId = (int) ($_POST['parent_id'] ?? 0);
    $requestedStatus = PageStatus::tryFrom((string) ($_POST['status'] ?? ''));
    // Quick Edit only ever offers Draft/Published (see PageService::
    // quickUpdate()'s docblock) — anything else submitted keeps the
    // page's current status, same as a contributor without
    // publish_posts always being forced to Draft in the full editor.
    $status = ($canPublish && in_array($requestedStatus, [PageStatus::Draft, PageStatus::Published], true))
        ? $requestedStatus
        : $existing->status;

    $updated = $pageService->quickUpdate($id, $title, $status, $parentId > 0 ? $parentId : null, $slug !== '' ? $slug : null);

    if ($updated === null) {
        http_response_code(422);
        echo json_encode(['error' => 'Could not save that page.']);
        exit;
    }

    echo json_encode([
        'data' => [
            'id' => $updated->id,
            'title' => $updated->title,
            'slug' => $updated->slug,
            'status' => $updated->status->value,
            'statusLabel' => $updated->status->label(),
        ],
    ]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

    if ($form === 'trash') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('page_trash_' . $id, $token)) {
            header('Location: ' . admin_url('pages/all-pages'));
            exit;
        }

        $existing = $id > 0 ? $pageService->findById($id) : null;

        if ($existing !== null && $canDeletePages && $canEditPage($existing)) {
            $pageService->trash($id);
        }

        header('Location: ' . admin_url('pages/all-pages') . '?trashed=1');
        exit;
    } elseif ($form === 'restore_page') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('page_restore_page_' . $id, $token)) {
            header('Location: ' . admin_url('pages/all-pages'));
            exit;
        }

        $existing = $id > 0 ? $pageService->findById($id) : null;

        if ($existing !== null && $canDeletePages && $canEditPage($existing)) {
            $pageService->restore($id);
        }

        header('Location: ' . admin_url('pages/all-pages') . '?status=' . PageStatus::Trashed->value . '&page_restored=1');
        exit;
    } elseif ($form === 'delete_permanently') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('page_delete_permanently_' . $id, $token)) {
            header('Location: ' . admin_url('pages/all-pages'));
            exit;
        }

        $existing = $id > 0 ? $pageService->findById($id) : null;

        // Permanent delete is only offered (and only honoured) for pages
        // already in the Trash — Move to Trash is the only reachable path
        // to actually removing a page from every other status, the same
        // "delete means trash first" guardrail admin/views/posts/all-posts.php
        // enforces.
        if ($existing !== null && $existing->status === PageStatus::Trashed && $canDeletePages && $canEditPage($existing)) {
            // Revisions live outside PageService (see RevisionService's
            // docblock) — clean them up here before the page itself is gone.
            $kernel->revisions->deleteAllFor(RevisionableType::Page, $id);
            $pageService->delete($id);
        }

        header('Location: ' . admin_url('pages/all-pages') . '?status=' . PageStatus::Trashed->value . '&page_deleted=1');
        exit;
    } elseif ($form === 'duplicate') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('page_duplicate_' . $id, $token)) {
            header('Location: ' . admin_url('pages/all-pages'));
            exit;
        }

        $existing = $id > 0 ? $pageService->findById($id) : null;

        if ($existing !== null && $canEditPage($existing)) {
            $duplicate = $pageService->duplicate($id, $currentUser->id);

            if ($duplicate !== null) {
                header('Location: ' . admin_url('pages/new') . '?id=' . $duplicate->id . '&duplicated=1');
                exit;
            }
        }

        header('Location: ' . admin_url('pages/all-pages'));
        exit;
    } elseif ($form === 'reposition_page') {
        // Drag-and-drop reordering (LP-009 Hierarchy UI), tree view only
        // (the "All" tab) — sortable.js fills these fields and submits
        // this one shared form on drop, same pattern
        // admin/views/appearance/menus.php's reposition_item uses.
        $draggedId = (int) ($_POST['dragged_id'] ?? 0);
        $targetId = (int) ($_POST['target_id'] ?? 0);
        $positionValue = (string) ($_POST['position'] ?? 'before');
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (Csrf::verify('page_reposition', $token) && $draggedId > 0 && $targetId > 0) {
            $existing = $pageService->findById($draggedId);

            if ($existing !== null && $canEditPage($existing)) {
                $pageService->reorder($draggedId, $targetId, $positionValue === 'after' ? 'after' : 'before');
            }
        }

        header('Location: ' . admin_url('pages/all-pages') . '?saved=1');
        exit;
    } elseif ($form === 'bulk_action' && Csrf::verify('pages_bulk_action', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $bulkAction = (string) ($_POST['bulk_action'] ?? '');
        $ids = array_values(array_filter(array_map('intval', is_array($_POST['page_ids'] ?? null) ? $_POST['page_ids'] : [])));
        $editableIds = array_values(array_filter($ids, static function (int $id) use ($pageService, $canEditPage): bool {
            $existing = $pageService->findById($id);

            return $existing !== null && $canEditPage($existing);
        }));

        if ($bulkAction === 'change_parent' && $canEditOthersPages) {
            $targetParentId = (int) ($_POST['target_parent_id'] ?? 0);

            foreach ($editableIds as $id) {
                $pageService->setParent($id, $targetParentId > 0 ? $targetParentId : null);
            }

            header('Location: ' . admin_url('pages/all-pages') . (isset($_POST['status']) ? '?status=' . urlencode((string) $_POST['status']) : ''));
            exit;
        }

        if ($bulkAction === 'change_author' && $canEditOthersPages) {
            $targetAuthorId = (int) ($_POST['target_author_id'] ?? 0);

            if ($targetAuthorId > 0) {
                $pageService->bulkReassignAuthor($editableIds, $targetAuthorId);
            }

            header('Location: ' . admin_url('pages/all-pages') . (isset($_POST['status']) ? '?status=' . urlencode((string) $_POST['status']) : ''));
            exit;
        }

        if (($bulkAction === 'set_public' || $bulkAction === 'set_private') && $canPublish) {
            $pageService->bulkSetVisibility($editableIds, $bulkAction === 'set_private' ? PageVisibility::Private : PageVisibility::Public);

            header('Location: ' . admin_url('pages/all-pages') . (isset($_POST['status']) ? '?status=' . urlencode((string) $_POST['status']) : ''));
            exit;
        }

        foreach ($editableIds as $id) {
            $existing = $pageService->findById($id);

            if ($existing === null) {
                continue;
            }

            if ($bulkAction === 'trash' && $canDeletePages) {
                $pageService->trash($id);
            } elseif ($bulkAction === 'restore' && $canDeletePages) {
                $pageService->restore($id);
            } elseif ($bulkAction === 'delete_permanently' && $canDeletePages && $existing->status === PageStatus::Trashed) {
                $kernel->revisions->deleteAllFor(RevisionableType::Page, $id);
                $pageService->delete($id);
            } elseif ($bulkAction === 'draft' && $canPublish) {
                $pageService->setStatus($id, PageStatus::Draft);
            } elseif ($bulkAction === 'publish' && $canPublish) {
                $pageService->setStatus($id, PageStatus::Published);
            }
        }

        header('Location: ' . admin_url('pages/all-pages') . (isset($_POST['status']) ? '?status=' . urlencode((string) $_POST['status']) : ''));
        exit;
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

$allPagesForParentFilter = $pageService->listAllForParentSelect();
$allUsersForFilter = $kernel->users->listAll();

if ($isTreeView) {
    $treeRows = $pageService->listAllForTree();
    $listedPages = array_map(static fn (array $row): Page => $row['page'], $treeRows);
} else {
    $pagination = $pageService->paginateForAdmin($page, statusFilter: $statusFilter, filters: $listFilters);
    $listedPages = $pagination['pages'];
}
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
                            <option value="<?= (int) $parentFilterOption['id'] ?>" <?= $parentFilter === $parentFilterOption['id'] ? 'selected' : '' ?>><?= esc_html($parentFilterOption['title']) ?></option>
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
    <?php if ($listedPages === []): ?>
        <p class="lp-admin__widget-placeholder"><?= $isTrashView ? 'Trash is empty.' : 'No pages yet.' ?></p>
    <?php else: ?>
        <?php
        /*
         * The bulk-action form and (in tree view) the sortable-group's
         * own reposition form are siblings, not nested — a <form>
         * inside another <form> is invalid HTML and the browser's
         * parse-error recovery silently closes the outer one at the
         * first </form> it hits (see all-posts.php's identical note).
         * In tree view, the bulk-action form has no visible table/list
         * inside it at all; every checkbox instead uses the HTML5
         * form="pages-bulk-form" attribute to submit into it despite
         * living in the separate <ul data-lp-sortable-group> below.
         */
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
                            <option value="<?= (int) $parentFilterOption['id'] ?>"><?= esc_html($parentFilterOption['title']) ?></option>
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
                            <th scope="col">Title</th>
                            <th scope="col">Status</th>
                            <th scope="col">Date</th>
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
                                <td><?= esc_html(($listedPage->publishedAt ?? $listedPage->updatedAt)->format('M j, Y')) ?></td>
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
                                                <a href="<?= esc_url(page_permalink($listedPage)) ?>" target="_blank" rel="noopener">View</a>
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
                                    <td colspan="5">
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
                                                            <option value="<?= (int) $parentOption['id'] ?>" <?= $listedPage->parentId === $parentOption['id'] ? 'selected' : '' ?>><?= esc_html($parentOption['title']) ?></option>
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
                                <a href="<?= esc_url(page_permalink($listedPage)) ?>" target="_blank" rel="noopener">View</a>
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
        /*
         * Out-of-band target forms for each row's Duplicate/Trash/Restore/
         * Delete Permanently button above (same LP-068 nested-form fix
         * all-posts.php uses) — standalone, empty <form>s the buttons
         * point at via the HTML `form=""` attribute instead of being
         * descendants of pages-bulk-form or the sortable group's own
         * reposition form.
         */
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
                    /*
                     * Quick Edit's form (LP-009) — unlike the other
                     * out-of-band forms above, this one carries real
                     * visible fields (via form="" on each input/select
                     * in the row above), submitted as JSON by
                     * quick-edit.js rather than a normal redirect.
                     * Tree view has no Quick Edit row this pass, so no
                     * form is rendered there.
                     */
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
