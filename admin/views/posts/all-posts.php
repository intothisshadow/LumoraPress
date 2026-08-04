<?php
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\Post;
use LumoraPress\Models\PostStatus;
use LumoraPress\Models\RevisionableType;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$postService = $kernel->posts;
$canPublish = $currentUser->can('publish_posts');
$canDeletePosts = $currentUser->can('delete_posts');
$canEditOthersPosts = $currentUser->can('edit_others_posts');

$error = null;

/**
 * An author/contributor may only touch their own posts unless they hold
 * edit_others_posts (Administrator/Editor).
 */
$canEditPost = static fn (Post $post): bool => $canEditOthersPosts || $post->authorId === $currentUser->id;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

    if ($form === 'quick_draft' && Csrf::verify('quick_draft', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $title = trim((string) ($_POST['title'] ?? ''));

        if ($title !== '') {
            $post = $postService->create(
                title: $title,
                content: trim((string) ($_POST['content'] ?? '')),
                excerpt: '',
                authorId: $currentUser->id,
                status: PostStatus::Draft,
            );

            header('Location: ' . admin_url('posts/new') . '?id=' . $post->id);
            exit;
        }

        $error = 'A title is required to save a draft.';
    } elseif ($form === 'trash') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('post_trash_' . $id, $token)) {
            header('Location: ' . admin_url('posts/all-posts'));
            exit;
        }

        $existing = $id > 0 ? $postService->findById($id) : null;

        if ($existing !== null && $canDeletePosts && $canEditPost($existing)) {
            $postService->trash($id);
        }

        header('Location: ' . admin_url('posts/all-posts') . '?trashed=1');
        exit;
    } elseif ($form === 'restore_post') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('post_restore_post_' . $id, $token)) {
            header('Location: ' . admin_url('posts/all-posts'));
            exit;
        }

        $existing = $id > 0 ? $postService->findById($id) : null;

        if ($existing !== null && $canDeletePosts && $canEditPost($existing)) {
            $postService->restore($id);
        }

        header('Location: ' . admin_url('posts/all-posts') . '?status=' . PostStatus::Trashed->value . '&post_restored=1');
        exit;
    } elseif ($form === 'delete_permanently') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('post_delete_permanently_' . $id, $token)) {
            header('Location: ' . admin_url('posts/all-posts'));
            exit;
        }

        $existing = $id > 0 ? $postService->findById($id) : null;

        // Permanent delete is only offered (and only honoured) for posts
        // already in the Trash — Move to Trash is the only reachable path
        // to actually removing a post from every other status, the same
        // "delete means trash first" guardrail WordPress's own list table
        // enforces.
        if ($existing !== null && $existing->status === PostStatus::Trashed && $canDeletePosts && $canEditPost($existing)) {
            $kernel->revisions->deleteAllFor(RevisionableType::Post, $id);
            $postService->delete($id);
        }

        header('Location: ' . admin_url('posts/all-posts') . '?status=' . PostStatus::Trashed->value . '&post_deleted=1');
        exit;
    } elseif ($form === 'duplicate') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('post_duplicate_' . $id, $token)) {
            header('Location: ' . admin_url('posts/all-posts'));
            exit;
        }

        $existing = $id > 0 ? $postService->findById($id) : null;

        if ($existing !== null && $canEditPost($existing)) {
            $duplicate = $postService->duplicate($id, $currentUser->id);

            if ($duplicate !== null) {
                $kernel->categories->assignToPost(
                    $duplicate->id,
                    array_map(static fn ($category) => (string) $category->id, $kernel->categories->categoriesForPost($existing->id)),
                );
                $kernel->tags->assignToPost(
                    $duplicate->id,
                    array_map(static fn ($tag) => $tag->name, $kernel->tags->tagsForPost($existing->id)),
                );

                header('Location: ' . admin_url('posts/new') . '?id=' . $duplicate->id . '&duplicated=1');
                exit;
            }
        }

        header('Location: ' . admin_url('posts/all-posts'));
        exit;
    } elseif ($form === 'bulk_action' && Csrf::verify('posts_bulk_action', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $bulkAction = (string) ($_POST['bulk_action'] ?? '');
        $ids = array_filter(array_map('intval', is_array($_POST['post_ids'] ?? null) ? $_POST['post_ids'] : []));

        foreach ($ids as $id) {
            $existing = $postService->findById($id);

            if ($existing === null || !$canEditPost($existing)) {
                continue;
            }

            if ($bulkAction === 'trash' && $canDeletePosts) {
                $postService->trash($id);
            } elseif ($bulkAction === 'restore' && $canDeletePosts) {
                $postService->restore($id);
            } elseif ($bulkAction === 'delete_permanently' && $canDeletePosts && $existing->status === PostStatus::Trashed) {
                $kernel->revisions->deleteAllFor(RevisionableType::Post, $id);
                $postService->delete($id);
            } elseif ($bulkAction === 'draft' && $canPublish) {
                $postService->setStatus($id, PostStatus::Draft);
            } elseif ($bulkAction === 'publish' && $canPublish) {
                $postService->setStatus($id, PostStatus::Published);
            }
        }

        header('Location: ' . admin_url('posts/all-posts') . (isset($_POST['status']) ? '?status=' . urlencode((string) $_POST['status']) : ''));
        exit;
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

<?php if (($_GET['error'] ?? null) === 'forbidden'): ?>
    <div class="lp-alert lp-alert--error">You do not have permission to edit that post.</div>
<?php endif; ?>

<p><a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('posts/new')) ?>">Add New Post</a></p>

<?php
$page = max(1, (int) ($_GET['paged'] ?? 1));
$statusFilter = PostStatus::tryFrom((string) ($_GET['status'] ?? ''));
$pagination = $postService->paginateForAdmin($page, statusFilter: $statusFilter);
$isTrashView = $statusFilter === PostStatus::Trashed;

// Trash is excluded from "All" (see PostService::paginateForAdmin()'s
// docblock), so the "All" tab's own count is the other three statuses
// summed rather than a simple "no filter" count.
$statusCounts = [];

foreach (PostStatus::cases() as $statusCase) {
    $statusCounts[$statusCase->value] = $postService->countByStatus($statusCase);
}

$allCount = $statusCounts[PostStatus::Draft->value] + $statusCounts[PostStatus::Published->value] + $statusCounts[PostStatus::Scheduled->value];
$statusLinks = ['' => 'All (' . $allCount . ')', ...array_combine(
    array_map(static fn (PostStatus $status): string => $status->value, PostStatus::cases()),
    array_map(static fn (PostStatus $status): string => $status->label() . ' (' . $statusCounts[$status->value] . ')', PostStatus::cases()),
)];
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
                        <?php endif; ?>
                        <?php if ($canDeletePosts): ?><option value="trash">Move to Trash</option><?php endif; ?>
                    <?php endif; ?>
                </select>
                <button type="submit" class="lp-button lp-button--secondary">Apply</button>
            </p>

            <table class="lp-table">
                <thead>
                    <tr>
                        <th scope="col">
                            <label class="lp-visually-hidden" for="posts-select-all">Select all</label>
                            <input type="checkbox" id="posts-select-all" onclick="this.closest('table').querySelectorAll('input[name=&quot;post_ids[]&quot;]').forEach(function (box) { box.checked = this.checked; }, this)">
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
                            <td><?= esc_html($listedPost->updatedAt->format('M j, Y')) ?></td>
                            <td class="lp-admin__row-actions">
                                <?php if ($canEditPost($listedPost)): ?>
                                    <?php if ($isTrashView): ?>
                                        <form method="post" action="<?= esc_url(admin_url('posts/all-posts')) ?>" class="lp-admin__inline-form">
                                            <?= Csrf::field('post_restore_post_' . $listedPost->id) ?>
                                            <input type="hidden" name="form" value="restore_post">
                                            <input type="hidden" name="id" value="<?= (int) $listedPost->id ?>">
                                            <button type="submit" class="lp-button lp-button--link">Restore</button>
                                        </form>
                                        <?php if ($canDeletePosts): ?>
                                            <form method="post" action="<?= esc_url(admin_url('posts/all-posts')) ?>" class="lp-admin__inline-form" onsubmit="return confirm('Permanently delete this post? This cannot be undone.');">
                                                <?= Csrf::field('post_delete_permanently_' . $listedPost->id) ?>
                                                <input type="hidden" name="form" value="delete_permanently">
                                                <input type="hidden" name="id" value="<?= (int) $listedPost->id ?>">
                                                <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Delete Permanently</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <form method="post" action="<?= esc_url(admin_url('posts/all-posts')) ?>" class="lp-admin__inline-form">
                                            <?= Csrf::field('post_duplicate_' . $listedPost->id) ?>
                                            <input type="hidden" name="form" value="duplicate">
                                            <input type="hidden" name="id" value="<?= (int) $listedPost->id ?>">
                                            <button type="submit" class="lp-button lp-button--link">Duplicate</button>
                                        </form>
                                        <?php if ($canDeletePosts): ?>
                                            <form method="post" action="<?= esc_url(admin_url('posts/all-posts')) ?>" class="lp-admin__inline-form" onsubmit="return confirm('Move this post to the Trash?');">
                                                <?= Csrf::field('post_trash_' . $listedPost->id) ?>
                                                <input type="hidden" name="form" value="trash">
                                                <input type="hidden" name="id" value="<?= (int) $listedPost->id ?>">
                                                <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Trash</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>

        <?php render_pagination($pagination); ?>
    <?php endif; ?>
</section>
