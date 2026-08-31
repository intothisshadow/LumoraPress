<?php

/**
 * The admin Comments screen: the moderation queue and comment management.
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
use LumoraPress\Models\Comment;
use LumoraPress\Models\CommentStatus;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$commentService = $kernel->comments;
$error = null;

/**
 * Tells Akismet when a human moderator overturned its (or the local trust
 * signal's) original call, so its model improves — shared by the
 * single-row "moderate" handler and the bulk-action handler below (LP-025),
 * since a bulk approve/spam is still a human moderation decision Akismet
 * should learn from the same way a single-row one already does.
 */
$submitAkismetFeedback = function (Comment $previousComment, CommentStatus $newStatus) use ($kernel): void {
    if (!$kernel->akismet->isEnabled() || $previousComment->status === $newStatus) {
        return;
    }

    // Exactly one of postId/pageId is set (see Comment's own docblock).
    $permalink = home_url();

    if ($previousComment->pageId !== null) {
        $page = $kernel->pages->findById($previousComment->pageId);
        $permalink = $page !== null ? page_permalink($page) : $permalink;
    } else {
        $post = $kernel->posts->findById($previousComment->postId);
        $permalink = $post !== null ? post_permalink($post) : $permalink;
    }

    $akismetComment = [
        'comment_type' => 'comment',
        'comment_author' => $previousComment->guestName,
        'comment_author_email' => $previousComment->guestEmail,
        'comment_author_url' => $previousComment->guestUrl,
        'comment_content' => $previousComment->content,
        'user_ip' => $previousComment->ipAddress ?? '0.0.0.0',
        'user_agent' => $previousComment->userAgent,
        'referrer' => null,
        'permalink' => $permalink,
    ];

    if ($newStatus === CommentStatus::Spam) {
        $kernel->akismet->submitSpam($akismetComment);
    } elseif ($newStatus === CommentStatus::Approved && $previousComment->status === CommentStatus::Spam) {
        $kernel->akismet->submitHam($akismetComment);
    }
};

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
            $previousComment = $commentService->findById($id);
            $commentService->updateStatus($id, $newStatus);

            if ($previousComment !== null) {
                $submitAkismetFeedback($previousComment, $newStatus);
            }
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
    } elseif ($form === 'empty_trash' && Csrf::verify('comments_empty_trash', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $commentService->emptyTrash();

        header('Location: ' . admin_url('comments') . '?status=' . CommentStatus::Trash->value . '&trash_emptied=1');
        exit;
    } elseif ($form === 'empty_spam' && Csrf::verify('comments_empty_spam', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $commentService->emptyByStatus(CommentStatus::Spam);

        header('Location: ' . admin_url('comments') . '?status=' . CommentStatus::Spam->value . '&spam_emptied=1');
        exit;
    } elseif ($form === 'bulk_action' && Csrf::verify('comments_bulk_action', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $bulkAction = (string) ($_POST['bulk_action'] ?? '');
        $ids = array_values(array_filter(array_map('intval', is_array($_POST['comment_ids'] ?? null) ? $_POST['comment_ids'] : [])));
        $bulkStatus = match ($bulkAction) {
            'approve' => CommentStatus::Approved,
            'unapprove' => CommentStatus::Pending,
            'spam' => CommentStatus::Spam,
            'trash' => CommentStatus::Trash,
            default => null,
        };

        foreach ($ids as $id) {
            if ($bulkAction === 'delete') {
                $commentService->delete($id);

                continue;
            }

            if ($bulkStatus === null) {
                continue;
            }

            $previousComment = $commentService->findById($id);

            if ($previousComment === null) {
                continue;
            }

            $commentService->updateStatus($id, $bulkStatus);
            $submitAkismetFeedback($previousComment, $bulkStatus);
        }

        header('Location: ' . admin_url('comments') . (isset($_GET['status']) ? '?status=' . esc_attr((string) $_GET['status']) : ''));
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

<?php if (isset($_GET['trash_emptied'])): ?>
    <div class="lp-alert lp-alert--success">Trash emptied.</div>
<?php endif; ?>

<?php if (isset($_GET['spam_emptied'])): ?>
    <div class="lp-alert lp-alert--success">Spam emptied.</div>
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
            <button type="submit" class="lp-button lp-button--primary">Save</button>
        </form>
    </section>

    <?php
    $page = max(1, (int) ($_GET['paged'] ?? 1));
    $statusFilter = CommentStatus::tryFrom((string) ($_GET['status'] ?? ''));
    $isTrashView = $statusFilter === CommentStatus::Trash;
    $isSpamView = $statusFilter === CommentStatus::Spam;
    $pagination = $commentService->paginateForAdmin($page, statusFilter: $statusFilter);

    // LP-135: "All" excludes Trash (see paginateForAdmin()'s matching
    // exclusion), so its own count is every other status summed rather
    // than a simple total-row-count query.
    $statusCounts = [];

    foreach (CommentStatus::cases() as $statusCase) {
        $statusCounts[$statusCase->value] = $commentService->countByStatus($statusCase);
    }

    $allCount = $statusCounts[CommentStatus::Pending->value] + $statusCounts[CommentStatus::Approved->value]
        + $statusCounts[CommentStatus::Spam->value];
    $statusLinks = ['' => 'All (' . $allCount . ')', ...array_combine(
        array_map(static fn (CommentStatus $status): string => $status->value, CommentStatus::cases()),
        array_map(static fn (CommentStatus $status): string => $status->label() . ' (' . $statusCounts[$status->value] . ')', CommentStatus::cases()),
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
        <?php if ($isTrashView && $pagination['total'] > 0): ?>
            <form method="post" action="<?= esc_url(admin_url('comments')) ?>" data-lp-confirm="Permanently delete every comment in the Trash? This cannot be undone.">
                <?= Csrf::field('comments_empty_trash') ?>
                <input type="hidden" name="form" value="empty_trash">
                <button type="submit" class="lp-button lp-button--danger">Empty Trash</button>
            </form>
        <?php endif; ?>

        <?php if ($isSpamView && $pagination['total'] > 0): ?>
            <form method="post" action="<?= esc_url(admin_url('comments')) ?>" data-lp-confirm="Permanently delete every comment marked as Spam? This cannot be undone.">
                <?= Csrf::field('comments_empty_spam') ?>
                <input type="hidden" name="form" value="empty_spam">
                <button type="submit" class="lp-button lp-button--danger">Empty Spam</button>
            </form>
        <?php endif; ?>

        <?php if ($pagination['comments'] === []): ?>
            <p class="lp-admin__widget-placeholder"><?= match (true) {
                $isTrashView => 'Trash is empty.',
                $isSpamView => 'No spam comments.',
                default => 'No comments yet.',
            } ?></p>
        <?php else: ?>
            <form method="post" action="<?= esc_url(admin_url('comments')) ?>" data-lp-bulk-form>
                <?= Csrf::field('comments_bulk_action') ?>
                <input type="hidden" name="form" value="bulk_action">
                <?php if ($statusFilter !== null): ?>
                    <input type="hidden" name="status" value="<?= esc_attr($statusFilter->value) ?>">
                <?php endif; ?>

                <p class="lp-admin__bulk-actions">
                    <label class="lp-visually-hidden" for="comments-bulk-action">Bulk action</label>
                    <select id="comments-bulk-action" name="bulk_action">
                        <option value="">Bulk actions</option>
                        <option value="approve">Approve</option>
                        <option value="unapprove">Unapprove</option>
                        <option value="spam">Mark as Spam</option>
                        <option value="trash">Move to Trash</option>
                        <option value="delete">Delete Permanently</option>
                    </select>
                    <button type="submit" class="lp-button lp-button--secondary">Apply</button>
                </p>

                <table class="lp-table">
                    <thead>
                        <tr>
                            <th scope="col">
                                <label class="lp-visually-hidden" for="comments-select-all">Select all</label>
                                <input type="checkbox" id="comments-select-all" data-lp-select-all="comment_ids[]" data-lp-select-all-scope="table">
                            </th>
                            <th scope="col">Author</th>
                            <th scope="col">Comment</th>
                            <th scope="col">Post / Page</th>
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
                                    <label class="lp-visually-hidden" for="comment-select-<?= (int) $comment->id ?>">Select comment from "<?= esc_attr($comment->guestName) ?>"</label>
                                    <input type="checkbox" id="comment-select-<?= (int) $comment->id ?>" name="comment_ids[]" value="<?= (int) $comment->id ?>">
                                </td>
                                <td>
                                    <?= esc_html($comment->guestName) ?><br>
                                    <span class="lp-field__hint"><?= esc_html($comment->guestEmail) ?></span>
                                </td>
                                <td>
                                    <a href="<?= esc_url(admin_url('comments')) ?>?action=edit&id=<?= (int) $comment->id ?>">
                                        <?= esc_html(mb_strimwidth($comment->content, 0, 80, '…')) ?>
                                    </a>
                                </td>
                                <?php
                                if ($row['contentType'] === 'page') {
                                    $rowPage = $kernel->pages->findById($comment->pageId);
                                    $rowPermalink = ($rowPage !== null ? page_permalink($rowPage) : home_url('page/' . $row['contentSlug'])) . '#comment-' . (int) $comment->id;
                                } else {
                                    $rowPost = $kernel->posts->findById($comment->postId);
                                    $rowPermalink = ($rowPost !== null ? post_permalink($rowPost) : home_url('post/' . $row['contentSlug'])) . '#comment-' . (int) $comment->id;
                                }
                                ?>
                                <td>
                                    <a href="<?= esc_url($rowPermalink) ?>"><?= esc_html($row['contentTitle']) ?></a>
                                </td>
                                <td>
                                    <span class="lp-status-badge lp-status-badge--<?= esc_attr($comment->status->value) ?>">
                                        <?= esc_html($comment->status->label()) ?>
                                    </span>
                                </td>
                                <td><?= esc_html($comment->createdAt->format('M j, Y')) ?></td>
                                <td class="lp-admin__row-actions">
                                    <?php foreach ([
                                        [CommentStatus::Approved, 'Approve', false],
                                        [CommentStatus::Pending, 'Unapprove', false],
                                        [CommentStatus::Spam, 'Spam', true],
                                        [CommentStatus::Trash, 'Trash', true],
                                    ] as [$targetStatus, $actionLabel, $isDanger]): ?>
                                        <?php if ($comment->status !== $targetStatus): ?>
                                            <?php $moderateFormId = 'comment-moderate-form-' . $comment->id . '-' . $targetStatus->value; ?>
                                            <span class="lp-admin__inline-form">
                                                <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('comment_moderate_' . $comment->id . '_' . $targetStatus->value)) ?>" form="<?= esc_attr($moderateFormId) ?>">
                                                <input type="hidden" name="form" value="moderate" form="<?= esc_attr($moderateFormId) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $comment->id ?>" form="<?= esc_attr($moderateFormId) ?>">
                                                <input type="hidden" name="status" value="<?= esc_attr($targetStatus->value) ?>" form="<?= esc_attr($moderateFormId) ?>">
                                                <button type="submit" class="lp-button lp-button--link<?= $isDanger ? ' lp-button--link--danger' : '' ?>" form="<?= esc_attr($moderateFormId) ?>"><?= esc_html($actionLabel) ?></button>
                                            </span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php $deleteFormId = 'comment-delete-form-' . $comment->id; ?>
                                    <span class="lp-admin__inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('comment_delete_' . $comment->id)) ?>" form="<?= esc_attr($deleteFormId) ?>">
                                        <input type="hidden" name="form" value="delete" form="<?= esc_attr($deleteFormId) ?>">
                                        <input type="hidden" name="id" value="<?= (int) $comment->id ?>" form="<?= esc_attr($deleteFormId) ?>">
                                        <button type="submit" class="lp-button lp-button--link lp-button--link--danger" form="<?= esc_attr($deleteFormId) ?>" data-lp-confirm="Delete this comment permanently? Replies will be kept but become top-level.">Delete</button>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <?php
            /*
             * Out-of-band target forms for each row action button above —
             * a nested <form> can't be used here since the enclosing
             * bulk-action form already wraps the whole table (see
             * admin/views/users.php's identical pattern/docblock): the
             * browser's parse-error recovery would silently close the
             * outer bulk-action form as soon as it hit the first inner
             * </form> tag.
             */
            foreach ($pagination['comments'] as $row):
                $comment = $row['comment'];

                foreach (CommentStatus::cases() as $targetStatus):
                    if ($comment->status === $targetStatus) {
                        continue;
                    }
                    ?>
                    <form id="comment-moderate-form-<?= (int) $comment->id ?>-<?= esc_attr($targetStatus->value) ?>" method="post" action="<?= esc_url(admin_url('comments')) ?><?= $statusFilter !== null ? '?status=' . esc_attr($statusFilter->value) : '' ?>"></form>
                    <?php
                endforeach;
                ?>
                <form id="comment-delete-form-<?= (int) $comment->id ?>" method="post" action="<?= esc_url(admin_url('comments')) ?>"></form>
                <?php
            endforeach;
            ?>

            <?php render_pagination($pagination, 'Comments pagination'); ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
