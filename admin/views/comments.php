<?php
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\CommentStatus;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$commentService = $kernel->comments;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

    if ($form === 'settings' && Csrf::verify('comment_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $kernel->config->setOption('comments_enabled', ($_POST['comments_enabled'] ?? '') === '1' ? '1' : '0');

        header('Location: ' . admin_url('comments') . '?saved=1');
        exit;
    } elseif ($form === 'edit' && Csrf::verify('comment_edit', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $id = (int) ($_POST['id'] ?? 0);
        $content = trim((string) ($_POST['content'] ?? ''));

        if ($content === '') {
            $error = 'A comment cannot be empty.';
        } else {
            $commentService->updateContent($id, $content);

            header('Location: ' . admin_url('comments') . '?action=edit&id=' . $id . '&saved=1');
            exit;
        }
    } elseif ($form === 'moderate') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
        $newStatus = CommentStatus::tryFrom((string) ($_POST['status'] ?? ''));

        if ($newStatus !== null && Csrf::verify('comment_moderate_' . $id . '_' . $newStatus->value, $token)) {
            $commentService->updateStatus($id, $newStatus);
        }

        header('Location: ' . admin_url('comments') . (isset($_GET['status']) ? '?status=' . esc_attr((string) $_GET['status']) : ''));
        exit;
    } elseif ($form === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (Csrf::verify('comment_delete_' . $id, $token)) {
            $commentService->delete($id);
        }

        header('Location: ' . admin_url('comments'));
        exit;
    }
}

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : 'list';
$editingId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$editingComment = null;

if ($action === 'edit') {
    $editingComment = $editingId !== null ? $commentService->findById($editingId) : null;

    if ($editingComment === null) {
        header('Location: ' . admin_url('comments') . '?error=forbidden');
        exit;
    }
}
?>
<h1 class="lp-admin__title">Comments</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if (($_GET['error'] ?? null) === 'forbidden'): ?>
    <div class="lp-alert lp-alert--error">That comment could not be found.</div>
<?php endif; ?>

<?php if ($action === 'edit'): ?>
    <section class="lp-admin__panel">
        <form method="post" action="<?= esc_url(admin_url('comments')) ?>">
            <?= Csrf::field('comment_edit') ?>
            <input type="hidden" name="form" value="edit">
            <input type="hidden" name="id" value="<?= (int) $editingComment->id ?>">

            <p class="lp-field">
                <label>From</label>
                <span><?= esc_html($editingComment->guestName) ?> &lt;<?= esc_html($editingComment->guestEmail) ?>&gt;</span>
            </p>

            <p class="lp-field">
                <label for="comment-content">Comment</label>
                <textarea id="comment-content" name="content" rows="6" required><?= esc_html($editingComment->content) ?></textarea>
            </p>

            <button type="submit" class="lp-button lp-button--primary">Save Comment</button>
            <a class="lp-button" href="<?= esc_url(admin_url('comments')) ?>">Cancel</a>
        </form>
    </section>
<?php else: ?>
    <section class="lp-admin__panel">
        <form method="post" action="<?= esc_url(admin_url('comments')) ?>">
            <?= Csrf::field('comment_settings') ?>
            <input type="hidden" name="form" value="settings">
            <label class="lp-field--checkbox">
                <input type="checkbox" name="comments_enabled" value="1" <?= $kernel->config->option('comments_enabled', '1') !== '0' ? 'checked' : '' ?>>
                Allow comments site-wide
            </label>
            <button type="submit" class="lp-button">Save</button>
        </form>
    </section>

    <?php
    $page = max(1, (int) ($_GET['paged'] ?? 1));
    $statusFilter = CommentStatus::tryFrom((string) ($_GET['status'] ?? ''));
    $pagination = $commentService->paginateForAdmin($page, statusFilter: $statusFilter);
    $statusLinks = ['' => 'All', ...array_combine(
        array_map(static fn (CommentStatus $status): string => $status->value, CommentStatus::cases()),
        array_map(static fn (CommentStatus $status): string => $status->label(), CommentStatus::cases()),
    )];
    ?>

    <p class="lp-admin__filters">
        <?php foreach ($statusLinks as $value => $label): ?>
            <a
                href="<?= esc_url(admin_url('comments')) ?><?= $value !== '' ? '?status=' . esc_attr($value) : '' ?>"
                class="<?= ($statusFilter?->value ?? '') === $value ? 'is-active' : '' ?>"
            ><?= esc_html($label) ?></a>
        <?php endforeach; ?>
    </p>

    <section class="lp-admin__panel">
        <?php if ($pagination['comments'] === []): ?>
            <p class="lp-admin__widget-placeholder">No comments yet.</p>
        <?php else: ?>
            <table class="lp-table">
                <thead>
                    <tr>
                        <th scope="col">Author</th>
                        <th scope="col">Comment</th>
                        <th scope="col">Post</th>
                        <th scope="col">Status</th>
                        <th scope="col">Date</th>
                        <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagination['comments'] as $row): ?>
                        <?php $comment = $row['comment']; ?>
                        <tr>
                            <td>
                                <?= esc_html($comment->guestName) ?><br>
                                <span class="lp-field__hint"><?= esc_html($comment->guestEmail) ?></span>
                            </td>
                            <td>
                                <a href="<?= esc_url(admin_url('comments')) ?>?action=edit&id=<?= (int) $comment->id ?>">
                                    <?= esc_html(mb_strimwidth($comment->content, 0, 80, '…')) ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?= esc_url(home_url('post/' . $row['postSlug'])) ?>#comment-<?= (int) $comment->id ?>"><?= esc_html($row['postTitle']) ?></a>
                            </td>
                            <td>
                                <span class="lp-status-badge lp-status-badge--<?= esc_attr($comment->status->value) ?>">
                                    <?= esc_html($comment->status->label()) ?>
                                </span>
                            </td>
                            <td><?= esc_html($comment->createdAt->format('M j, Y')) ?></td>
                            <td class="lp-admin__row-actions">
                                <?php foreach ([
                                    [CommentStatus::Approved, 'Approve'],
                                    [CommentStatus::Pending, 'Unapprove'],
                                    [CommentStatus::Spam, 'Spam'],
                                    [CommentStatus::Trash, 'Trash'],
                                ] as [$targetStatus, $actionLabel]): ?>
                                    <?php if ($comment->status !== $targetStatus): ?>
                                        <form method="post" action="<?= esc_url(admin_url('comments')) ?><?= isset($_GET['status']) ? '?status=' . esc_attr((string) $_GET['status']) : '' ?>">
                                            <?= Csrf::field('comment_moderate_' . $comment->id . '_' . $targetStatus->value) ?>
                                            <input type="hidden" name="form" value="moderate">
                                            <input type="hidden" name="id" value="<?= (int) $comment->id ?>">
                                            <input type="hidden" name="status" value="<?= esc_attr($targetStatus->value) ?>">
                                            <button type="submit" class="lp-button lp-button--link-muted"><?= esc_html($actionLabel) ?></button>
                                        </form>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <form method="post" action="<?= esc_url(admin_url('comments')) ?>" onsubmit="return confirm('Delete this comment permanently? Replies will be kept but become top-level.');">
                                    <?= Csrf::field('comment_delete_' . $comment->id) ?>
                                    <input type="hidden" name="form" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $comment->id ?>">
                                    <button type="submit" class="lp-button lp-button--link">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php render_pagination($pagination, 'Comments pagination'); ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
