<?php

/**
 * The admin Posts list screen.
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

use LumoraPress\Controllers\Admin\PostsController;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\Post;
use LumoraPress\Models\PostStatus;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$postService = $kernel->posts;
$canPublish = $currentUser->can('publish_posts');
$canDeletePosts = $currentUser->can('delete_posts');
$canEditOthersPosts = $currentUser->can('edit_others_posts');
$canEditPosts = $currentUser->can('edit_posts');

$error = null;

/**
 * An author/contributor may only touch their own posts unless they hold
 * edit_others_posts (Administrator/Editor).
 */
$canEditPost = static fn (Post $post): bool => $canEditOthersPosts || $post->authorId === $currentUser->id;

// POST handling lives in PostsController; this view dispatches to it and
// turns the AdminActionResult into a redirect or $error string.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if ($form !== '') {
        $controller = new PostsController($kernel->posts, $kernel->categories, $kernel->tags, $kernel->revisions, $kernel->media, $kernel->thumbnails, $kernel->content);

        $result = match ($form) {
            'quick_draft' => $controller->quickDraft($_POST, $currentUser->id, $csrfToken),
            'trash' => $controller->trash($_POST, $currentUser->id, $canDeletePosts, $canEditOthersPosts, $csrfToken),
            'restore_post' => $controller->restorePost($_POST, $currentUser->id, $canDeletePosts, $canEditOthersPosts, $csrfToken),
            'delete_permanently' => $controller->deletePermanently($_POST, $currentUser->id, $canDeletePosts, $canEditOthersPosts, $csrfToken),
            'empty_trash' => $controller->emptyTrash($currentUser->id, $canDeletePosts, $canEditOthersPosts, $csrfToken),
            'duplicate' => $controller->duplicate($_POST, $currentUser->id, $canEditOthersPosts, $csrfToken),
            'bulk_action' => $controller->bulkAction($_POST, $currentUser->id, $canPublish, $canDeletePosts, $canEditOthersPosts, $canEditPosts, $csrfToken),
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
<h1 class="lp-admin__title">Posts</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['trashed'])): ?>
    <div class="lp-alert lp-alert--success">Post moved to Trash.</div>
<?php endif; ?>

<?php if (isset($_GET['post_restored'])): ?>
    <div class="lp-alert lp-alert--success">Post restored as a draft.</div>
<?php endif; ?>

<?php if (isset($_GET['post_deleted'])): ?>
    <div class="lp-alert lp-alert--success">Post permanently deleted.</div>
<?php endif; ?>

<?php if (isset($_GET['trash_emptied'])): ?>
    <div class="lp-alert lp-alert--success">Trash emptied.</div>
<?php endif; ?>

<?php if (($_GET['error'] ?? null) === 'forbidden'): ?>
    <div class="lp-alert lp-alert--error">You do not have permission to edit that post.</div>
<?php endif; ?>

<p><a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('posts/new')) ?>">Add New Post</a></p>

<?php
$page = max(1, (int) ($_GET['paged'] ?? 1));
$statusFilter = PostStatus::tryFrom((string) ($_GET['status'] ?? ''));
$isTrashView = $statusFilter === PostStatus::Trashed;

$termFilter = trim((string) ($_GET['q'] ?? ''));
$authorFilter = (int) ($_GET['author'] ?? 0);
$categoryFilter = (int) ($_GET['category'] ?? 0);
$tagFilter = (int) ($_GET['tag'] ?? 0);
$dateFromFilter = (string) ($_GET['date_from'] ?? '');
$dateToFilter = (string) ($_GET['date_to'] ?? '');
$listFilters = [
    'term' => $termFilter,
    'authorId' => $authorFilter,
    'categoryId' => $categoryFilter,
    'tagId' => $tagFilter,
    'dateFrom' => $dateFromFilter,
    'dateTo' => $dateToFilter,
];
$pagination = $postService->paginateForAdmin($page, statusFilter: $statusFilter, filters: $listFilters);

// Trash is excluded from "All" (see PostService::paginateForAdmin()'s
// docblock), so the "All" tab's own count is every non-Trashed status
// summed rather than a simple "no filter" count.
$statusCounts = [];

foreach (PostStatus::cases() as $statusCase) {
    $statusCounts[$statusCase->value] = $postService->countByStatus($statusCase);
}

$allCount = $statusCounts[PostStatus::Draft->value] + $statusCounts[PostStatus::PendingReview->value]
    + $statusCounts[PostStatus::Published->value] + $statusCounts[PostStatus::Scheduled->value];
$statusLinks = ['' => 'All (' . $allCount . ')', ...array_combine(
    array_map(static fn (PostStatus $status): string => $status->value, PostStatus::cases()),
    array_map(static fn (PostStatus $status): string => $status->label() . ' (' . $statusCounts[$status->value] . ')', PostStatus::cases()),
)];
$allUsersForFilter = $kernel->users->listAll();
$allCategoriesForFilter = $kernel->categories->listAllForParentPicker();
$allTagsForFilter = $kernel->tags->listAll();
?>

<p class="lp-admin__filters">
    <?php foreach ($statusLinks as $value => $label): ?>
        <a
            href="<?= esc_url(admin_url('posts/all-posts')) ?><?= $value !== '' ? '?status=' . esc_attr($value) : '' ?>"
            class="<?= ($statusFilter?->value ?? '') === $value ? 'is-active' : '' ?>"
        ><?= esc_html($label) ?></a>
    <?php endforeach; ?>
</p>

<section class="lp-admin__panel">
    <details class="lp-admin__collapsible">
        <summary>Search &amp; Filter</summary>
        <div class="lp-admin__collapsible__body">
            <form method="get" action="<?= esc_url(admin_url('posts/all-posts')) ?>" class="lp-admin__filter-form">
                <?php if ($statusFilter !== null): ?>
                    <input type="hidden" name="status" value="<?= esc_attr($statusFilter->value) ?>">
                <?php endif; ?>
                <p class="lp-field">
                    <label for="posts-q">Search title</label>
                    <input type="text" id="posts-q" name="q" value="<?= esc_attr($termFilter) ?>">
                </p>
                <p class="lp-field">
                    <label for="posts-author-filter">Author</label>
                    <select id="posts-author-filter" name="author">
                        <option value="0">All authors</option>
                        <?php foreach ($allUsersForFilter as $filterUser): ?>
                            <option value="<?= (int) $filterUser->id ?>" <?= $authorFilter === $filterUser->id ? 'selected' : '' ?>><?= esc_html($filterUser->displayName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p class="lp-field">
                    <label for="posts-category-filter">Category</label>
                    <select id="posts-category-filter" name="category">
                        <option value="0">All categories</option>
                        <?php foreach ($allCategoriesForFilter as $filterCategory): ?>
                            <option value="<?= (int) $filterCategory['id'] ?>" <?= $categoryFilter === $filterCategory['id'] ? 'selected' : '' ?>><?= str_repeat('&nbsp;&nbsp;&nbsp;', $filterCategory['depth']) . esc_html($filterCategory['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p class="lp-field">
                    <label for="posts-tag-filter">Tag</label>
                    <select id="posts-tag-filter" name="tag">
                        <option value="0">All tags</option>
                        <?php foreach ($allTagsForFilter as $filterTag): ?>
                            <option value="<?= (int) $filterTag->id ?>" <?= $tagFilter === $filterTag->id ? 'selected' : '' ?>><?= esc_html($filterTag->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p class="lp-field">
                    <label for="posts-date-from">Date from</label>
                    <input type="date" id="posts-date-from" name="date_from" value="<?= esc_attr($dateFromFilter) ?>">
                </p>
                <p class="lp-field">
                    <label for="posts-date-to">Date to</label>
                    <input type="date" id="posts-date-to" name="date_to" value="<?= esc_attr($dateToFilter) ?>">
                </p>
                <button type="submit" class="lp-button">Filter</button>
            </form>
        </div>
    </details>
</section>

<section class="lp-admin__panel">
    <?php if ($isTrashView && $statusCounts[PostStatus::Trashed->value] > 0): ?>
        <form method="post" action="<?= esc_url(admin_url('posts/all-posts')) ?>" data-lp-confirm="Permanently delete every post in the Trash? This cannot be undone.">
            <?= Csrf::field('posts_empty_trash') ?>
            <input type="hidden" name="form" value="empty_trash">
            <button type="submit" class="lp-button lp-button--danger">Empty Trash</button>
        </form>
    <?php endif; ?>

    <?php if ($pagination['posts'] === []): ?>
        <p class="lp-admin__widget-placeholder"><?= $isTrashView ? 'Trash is empty.' : 'No posts yet.' ?></p>
    <?php else: ?>
        <form method="post" action="<?= esc_url(admin_url('posts/all-posts')) ?>" data-lp-bulk-form>
            <?= Csrf::field('posts_bulk_action') ?>
            <input type="hidden" name="form" value="bulk_action">
            <?php if ($statusFilter !== null): ?>
                <input type="hidden" name="status" value="<?= esc_attr($statusFilter->value) ?>">
            <?php endif; ?>

            <p class="lp-admin__bulk-actions">
                <label class="lp-visually-hidden" for="posts-bulk-action">Bulk action</label>
                <select id="posts-bulk-action" name="bulk_action">
                    <option value="">Bulk actions</option>
                    <?php if ($isTrashView): ?>
                        <option value="restore">Restore</option>
                        <?php if ($canDeletePosts): ?><option value="delete_permanently">Delete Permanently</option><?php endif; ?>
                    <?php else: ?>
                        <?php if ($canPublish): ?>
                            <option value="publish">Publish</option>
                            <option value="draft">Mark as Draft</option>
                            <option value="set_public">Set Public</option>
                            <option value="set_private">Set Private</option>
                        <?php endif; ?>
                        <?php if ($canDeletePosts): ?><option value="trash">Move to Trash</option><?php endif; ?>
                        <?php if ($canEditOthersPosts): ?><option value="change_author">Change author to&hellip;</option><?php endif; ?>
                        <option value="add_category">Add category&hellip;</option>
                    <?php endif; ?>
                </select>
                <?php if ($canEditOthersPosts): ?>
                    <select name="target_author_id">
                        <?php foreach ($allUsersForFilter as $bulkAuthorOption): ?>
                            <option value="<?= (int) $bulkAuthorOption->id ?>"><?= esc_html($bulkAuthorOption->displayName) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <select name="target_category_id">
                    <option value="0">(Choose a category)</option>
                    <?php foreach ($allCategoriesForFilter as $bulkCategoryOption): ?>
                        <option value="<?= (int) $bulkCategoryOption['id'] ?>"><?= str_repeat('&nbsp;&nbsp;&nbsp;', $bulkCategoryOption['depth']) . esc_html($bulkCategoryOption['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="lp-button lp-button--secondary">Apply</button>
            </p>

            <table class="lp-table">
                <thead>
                    <tr>
                        <th scope="col">
                            <label class="lp-visually-hidden" for="posts-select-all">Select all</label>
                            <input type="checkbox" id="posts-select-all" data-lp-select-all="post_ids[]" data-lp-select-all-scope="table">
                        </th>
                        <th scope="col">Title</th>
                        <th scope="col">Status</th>
                        <th scope="col">Date</th>
                        <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagination['posts'] as $listedPost): ?>
                        <tr>
                            <td>
                                <?php if ($canEditPost($listedPost)): ?>
                                    <label class="lp-visually-hidden" for="post-select-<?= (int) $listedPost->id ?>">Select "<?= esc_html($listedPost->title) ?>"</label>
                                    <input type="checkbox" id="post-select-<?= (int) $listedPost->id ?>" name="post_ids[]" value="<?= (int) $listedPost->id ?>">
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$isTrashView && $canEditPost($listedPost)): ?>
                                    <a href="<?= esc_url(admin_url('posts/new')) ?>?id=<?= (int) $listedPost->id ?>"><?= esc_html($listedPost->title) ?></a>
                                <?php else: ?>
                                    <?= esc_html($listedPost->title) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="lp-status-badge lp-status-badge--<?= esc_attr($listedPost->status->value) ?>">
                                    <?= esc_html($listedPost->status->label()) ?>
                                </span>
                            </td>
                            <td><?= esc_html(($listedPost->publishedAt ?? $listedPost->updatedAt)->format('M j, Y')) ?></td>
                            <td class="lp-admin__row-actions">
                                <?php if ($canEditPost($listedPost)): ?>
                                    <?php if ($isTrashView): ?>
                                        <?php $restoreFormId = 'post-restore-form-' . $listedPost->id; ?>
                                        <span class="lp-admin__inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('post_restore_post_' . $listedPost->id)) ?>" form="<?= esc_attr($restoreFormId) ?>">
                                            <input type="hidden" name="form" value="restore_post" form="<?= esc_attr($restoreFormId) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $listedPost->id ?>" form="<?= esc_attr($restoreFormId) ?>">
                                            <button type="submit" class="lp-button lp-button--link" form="<?= esc_attr($restoreFormId) ?>">Restore</button>
                                        </span>
                                        <?php if ($canDeletePosts): ?>
                                            <?php $deletePermFormId = 'post-delete-permanently-form-' . $listedPost->id; ?>
                                            <span class="lp-admin__inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('post_delete_permanently_' . $listedPost->id)) ?>" form="<?= esc_attr($deletePermFormId) ?>">
                                                <input type="hidden" name="form" value="delete_permanently" form="<?= esc_attr($deletePermFormId) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $listedPost->id ?>" form="<?= esc_attr($deletePermFormId) ?>">
                                                <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($deletePermFormId) ?>" data-lp-confirm="Permanently delete this post? This cannot be undone.">Delete Permanently</button>
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($listedPost->status === PostStatus::Published): ?>
                                            <a class="lp-button lp-button--link lp-button--link--view" href="<?= esc_url(post_permalink($listedPost)) ?>" target="_blank" rel="noopener">View</a>
                                        <?php endif; ?>
                                        <?php $duplicateFormId = 'post-duplicate-form-' . $listedPost->id; ?>
                                        <span class="lp-admin__inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('post_duplicate_' . $listedPost->id)) ?>" form="<?= esc_attr($duplicateFormId) ?>">
                                            <input type="hidden" name="form" value="duplicate" form="<?= esc_attr($duplicateFormId) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $listedPost->id ?>" form="<?= esc_attr($duplicateFormId) ?>">
                                            <button type="submit" class="lp-button lp-button--link" form="<?= esc_attr($duplicateFormId) ?>">Duplicate</button>
                                        </span>
                                        <?php if ($canDeletePosts): ?>
                                            <?php $trashFormId = 'post-trash-form-' . $listedPost->id; ?>
                                            <span class="lp-admin__inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('post_trash_' . $listedPost->id)) ?>" form="<?= esc_attr($trashFormId) ?>">
                                                <input type="hidden" name="form" value="trash" form="<?= esc_attr($trashFormId) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $listedPost->id ?>" form="<?= esc_attr($trashFormId) ?>">
                                                <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($trashFormId) ?>" data-lp-confirm="Move this post to the Trash?">Trash</button>
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>

        <?php
        // Out-of-band target forms for each row action button — a <form>
        // nested inside another is invalid HTML and merges fields into
        // the outer bulk-action form. Each button targets one of these
        // standalone forms via form="" instead.
        foreach ($pagination['posts'] as $listedPost):
            if (!$canEditPost($listedPost)) {
                continue;
            }

            if ($isTrashView):
                ?>
                <form id="post-restore-form-<?= (int) $listedPost->id ?>" method="post" action="<?= esc_url(admin_url('posts/all-posts')) ?>"></form>
                <?php if ($canDeletePosts): ?>
                    <form id="post-delete-permanently-form-<?= (int) $listedPost->id ?>" method="post" action="<?= esc_url(admin_url('posts/all-posts')) ?>"></form>
                <?php endif; ?>
                <?php
            else:
                ?>
                <form id="post-duplicate-form-<?= (int) $listedPost->id ?>" method="post" action="<?= esc_url(admin_url('posts/all-posts')) ?>"></form>
                <?php if ($canDeletePosts): ?>
                    <form id="post-trash-form-<?= (int) $listedPost->id ?>" method="post" action="<?= esc_url(admin_url('posts/all-posts')) ?>"></form>
                <?php endif; ?>
                <?php
            endif;
        endforeach;
        ?>

        <?php render_pagination($pagination); ?>
    <?php endif; ?>
</section>
