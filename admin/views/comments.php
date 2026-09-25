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
use LumoraPress\Services\CommentNotificationService;
use LumoraPress\Services\CommentReplyService;
use LumoraPress\Services\CommentReportService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$commentService = $kernel->comments;
$commentReports = new CommentReportService($kernel->database, (string) $kernel->config->get('table_prefix', 'lp_'), $kernel->config);
$commentReplies = new CommentReplyService(
    $commentService,
    $kernel->posts,
    $kernel->pages,
    $kernel->commentModeration,
    new CommentNotificationService($kernel->config, $kernel->mailer, $kernel->users),
    $kernel->hooks,
);
$error = null;

// "Reported" is its own tab rather than one of the filters below, so the
// status tabs always lead out of it.
$reportedOnly = ($_GET['reported'] ?? '') === '1';

// Every filter below (search/user/IP/date) is carried through status-tab
// links, pagination (render_pagination() reads $_SERVER['REQUEST_URI']'s
// own query string, so it needs no help), and the redirect back after a
// moderate/bulk/delete action — built once here so every one of those
// stays in sync rather than each hand-building its own subset.
$searchFilter = trim((string) ($_GET['q'] ?? ''));
$userFilter = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int) $_GET['user_id'] : null;
$ipFilter = trim((string) ($_GET['ip'] ?? ''));
$dateFromFilter = trim((string) ($_GET['date_from'] ?? ''));
$dateToFilter = trim((string) ($_GET['date_to'] ?? ''));

$filterQuery = array_filter([
    'q' => $searchFilter,
    'user_id' => $userFilter,
    'ip' => $ipFilter,
    'date_from' => $dateFromFilter,
    'date_to' => $dateToFilter,
], static fn (mixed $value): bool => $value !== null && $value !== '');

// Tells Akismet when a moderator overturned its original call, so its
// model improves — shared by the single-row and bulk-action handlers.
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

    if ($form === 'edit' && Csrf::verify('comment_edit', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
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

        $redirectQuery = $filterQuery;

        if (isset($_GET['status'])) {
            $redirectQuery['status'] = (string) $_GET['status'];
        }

        if ($reportedOnly) {
            $redirectQuery['reported'] = '1';
        }

        header('Location: ' . admin_url('comments') . ($redirectQuery !== [] ? '?' . http_build_query($redirectQuery) : ''));
        exit;
    } elseif ($form === 'reply') {
        $id = (int) ($_POST['id'] ?? 0);
        $replyParent = $commentService->findById($id);

        if ($replyParent === null) {
            header('Location: ' . admin_url('comments') . '?error=forbidden');
            exit;
        }

        if (Csrf::verify('comment_reply_' . $id, is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
            $parentWasPending = $replyParent->status === CommentStatus::Pending;

            try {
                $commentReplies->reply(
                    $replyParent,
                    $currentUser,
                    (string) ($_POST['content'] ?? ''),
                    is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : null,
                    is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? $_SERVER['HTTP_USER_AGENT'] : null,
                );

                if ($parentWasPending) {
                    $submitAkismetFeedback($replyParent, CommentStatus::Approved);
                }

                header('Location: ' . admin_url('comments') . '?replied=' . ($parentWasPending ? 'approved' : '1'));
                exit;
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($form === 'dismiss_reports') {
        $id = (int) ($_POST['id'] ?? 0);

        if (Csrf::verify('comment_dismiss_reports_' . $id, is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
            $commentReports->dismiss($id);
        }

        $redirectQuery = $filterQuery + ($reportedOnly ? ['reported' => '1'] : []);
        header('Location: ' . admin_url('comments') . ($redirectQuery !== [] ? '?' . http_build_query($redirectQuery) : ''));
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

        $redirectQuery = $filterQuery;

        if (isset($_GET['status'])) {
            $redirectQuery['status'] = (string) $_GET['status'];
        }

        header('Location: ' . admin_url('comments') . ($redirectQuery !== [] ? '?' . http_build_query($redirectQuery) : ''));
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

$replyingTo = null;
$replyContentTitle = '';
$replyContentUrl = '';

if ($action === 'reply') {
    $replyingTo = $editingId !== null ? $commentService->findById($editingId) : null;

    if ($replyingTo === null) {
        header('Location: ' . admin_url('comments') . '?error=forbidden');
        exit;
    }

    if (!$commentReplies->canReplyTo($replyingTo)) {
        header('Location: ' . admin_url('comments') . '?error=not_repliable');
        exit;
    }

    $replyContent = $replyingTo->pageId !== null ? $kernel->pages->findById($replyingTo->pageId) : $kernel->posts->findById((int) $replyingTo->postId);
    $replyContentTitle = $replyContent?->title ?? '';
    $replyContentUrl = $replyContent === null ? '' : ($replyingTo->pageId !== null ? page_permalink($replyContent) : post_permalink($replyContent)) . '#comment-' . $replyingTo->id;
}

$tabs = ['moderation' => 'Moderation', 'statistics' => 'Statistics'];
$activeTab = in_array($_GET['tab'] ?? '', array_keys($tabs), true) ? $_GET['tab'] : 'moderation';
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

<?php if (($_GET['error'] ?? null) === 'not_repliable'): ?>
    <div class="lp-alert lp-alert--error">That comment can't be replied to. Only approved or pending comments on existing posts and pages can.</div>
<?php endif; ?>

<?php if (($_GET['replied'] ?? null) === 'approved'): ?>
    <div class="lp-alert lp-alert--success">The comment was approved and your reply was posted.</div>
<?php elseif (isset($_GET['replied'])): ?>
    <div class="lp-alert lp-alert--success">Your reply was posted.</div>
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
<?php elseif ($action === 'reply'): ?>
    <section class="lp-admin__panel">
        <h2>Reply to <?= esc_html($replyingTo->guestName) ?></h2>

        <div class="lp-comment-reply-original">
            <p class="lp-comment-reply-original__meta">
                <strong><?= esc_html($replyingTo->guestName) ?></strong>
                &middot; <?= esc_html($replyingTo->createdAt->format('M j, Y')) ?>
                <?php if ($replyContentTitle !== ''): ?>
                    &middot; on <a href="<?= esc_url($replyContentUrl) ?>"><?= esc_html($replyContentTitle) ?></a>
                <?php endif; ?>
                <?php if ($replyingTo->status === CommentStatus::Pending): ?>
                    <span class="lp-status-badge lp-status-badge--pending"><?= esc_html($replyingTo->status->label()) ?></span>
                <?php endif; ?>
            </p>
            <div class="lp-comment-reply-original__text"><?= format_comment_content($replyingTo->content) ?></div>
        </div>

        <form method="post" action="<?= esc_url(admin_url('comments') . '?action=reply&id=' . (int) $replyingTo->id) ?>">
            <?= Csrf::field('comment_reply_' . $replyingTo->id) ?>
            <input type="hidden" name="form" value="reply">
            <input type="hidden" name="id" value="<?= (int) $replyingTo->id ?>">

            <p class="lp-field">
                <label for="comment-reply-content">Your reply</label>
                <textarea id="comment-reply-content" name="content" rows="6" required autofocus><?= esc_html((string) ($_POST['content'] ?? '')) ?></textarea>
                <span class="lp-field__hint">Posted as <?= esc_html($currentUser->displayName) ?>, under this comment.<?= $replyingTo->status === CommentStatus::Pending ? ' Replying also approves this comment.' : '' ?></span>
            </p>

            <button type="submit" class="lp-button lp-button--primary"><?= $replyingTo->status === CommentStatus::Pending ? 'Approve and Reply' : 'Reply' ?></button>
            <a class="lp-button" href="<?= esc_url(admin_url('comments')) ?>">Cancel</a>
        </form>
    </section>
<?php else: ?>
    <div class="lp-tabs">
        <div class="lp-tabs__list" role="tablist" aria-label="Comments section">
            <?php foreach ($tabs as $tabKey => $tabLabel): ?>
                <button
                    type="button"
                    class="lp-tabs__tab"
                    id="lp-tab-<?= esc_attr($tabKey) ?>"
                    role="tab"
                    aria-selected="<?= $activeTab === $tabKey ? 'true' : 'false' ?>"
                    aria-controls="lp-tabpanel-<?= esc_attr($tabKey) ?>"
                    tabindex="<?= $activeTab === $tabKey ? '0' : '-1' ?>"
                ><?= esc_html($tabLabel) ?></button>
            <?php endforeach; ?>
        </div>

        <div class="lp-tabs__panel" id="lp-tabpanel-moderation" role="tabpanel" aria-labelledby="lp-tab-moderation"<?= $activeTab === 'moderation' ? '' : ' hidden' ?>>
    <?php
    $page = max(1, (int) ($_GET['paged'] ?? 1));
    $statusFilter = CommentStatus::tryFrom((string) ($_GET['status'] ?? ''));
    $isTrashView = $statusFilter === CommentStatus::Trash;
    $isSpamView = $statusFilter === CommentStatus::Spam;
    $pagination = $commentService->paginateForAdmin(
        $page,
        statusFilter: $statusFilter,
        search: $searchFilter !== '' ? $searchFilter : null,
        userId: $userFilter,
        ipAddress: $ipFilter !== '' ? $ipFilter : null,
        dateFrom: $dateFromFilter !== '' ? $dateFromFilter : null,
        dateTo: $dateToFilter !== '' ? $dateToFilter : null,
        reportedOnly: $reportedOnly,
    );
    $reportSummaries = $commentReports->summariesFor(array_map(static fn (array $row): int => $row['comment']->id, $pagination['comments']));
    $reportedCount = $commentReports->reportedCommentCount();
    $allUsersForFilter = $kernel->users->listAll();

    // The exact filter+status combination currently being viewed, as a
    // query string — every POST form below (bulk action, per-row
    // moderate/delete) submits its action to this same URL so the
    // redirect-back-to-current-view logic at the top of this file (which
    // reads $_GET, populated only from the URL a POST was submitted to,
    // not from whatever the browser last displayed) actually has
    // something to read.
    $currentViewQuery = $filterQuery;

    if ($statusFilter !== null) {
        $currentViewQuery['status'] = $statusFilter->value;
    }

    if ($reportedOnly) {
        $currentViewQuery['reported'] = '1';
    }

    $currentViewQueryString = $currentViewQuery !== [] ? '?' . http_build_query($currentViewQuery) : '';

    // "All" excludes Trash (see paginateForAdmin()'s matching exclusion),
    // so its own count is every other status summed rather than a simple
    // total-row-count query.
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
            <?php $tabQuery = $filterQuery; ?>
            <?php if ($value !== '') { $tabQuery['status'] = $value; } ?>
            <a
                href="<?= esc_url(admin_url('comments')) ?><?= $tabQuery !== [] ? '?' . http_build_query($tabQuery) : '' ?>"
                class="<?= !$reportedOnly && ($statusFilter?->value ?? '') === $value ? 'is-active' : '' ?>"
            ><?= esc_html($label) ?></a>
        <?php endforeach; ?>
        <?php if ($commentReports->isEnabled() || $reportedCount > 0): ?>
            <a
                href="<?= esc_url(admin_url('comments')) ?>?<?= esc_attr(http_build_query($filterQuery + ['reported' => '1'])) ?>"
                class="<?= $reportedOnly ? 'is-active' : '' ?>"
            >Reported (<?= (int) $reportedCount ?>)</a>
        <?php endif; ?>
    </p>

    <section class="lp-admin__panel">
        <details class="lp-admin__collapsible" <?= $filterQuery !== [] ? 'open' : '' ?>>
            <summary>Search &amp; Filter</summary>
            <div class="lp-admin__collapsible__body">
                <form method="get" action="<?= esc_url(admin_url('comments')) ?>" class="lp-admin__filter-form lp-comments-filter-form">
                    <?php if ($statusFilter !== null): ?>
                        <input type="hidden" name="status" value="<?= esc_attr($statusFilter->value) ?>">
                    <?php endif; ?>
                    <?php if ($reportedOnly): ?>
                        <input type="hidden" name="reported" value="1">
                    <?php endif; ?>

                    <p class="lp-field">
                        <label for="comments-filter-q">Search</label>
                        <input type="search" id="comments-filter-q" name="q" value="<?= esc_attr($searchFilter) ?>" placeholder="Comment content, name, or email">
                    </p>

                    <p class="lp-field">
                        <label for="comments-filter-user">User</label>
                        <select id="comments-filter-user" name="user_id">
                            <option value="">Any user</option>
                            <?php foreach ($allUsersForFilter as $filterUser): ?>
                                <option value="<?= (int) $filterUser->id ?>" <?= $userFilter === $filterUser->id ? 'selected' : '' ?>><?= esc_html($filterUser->displayName) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="lp-field__hint">A guest comment has no account, so this only matches signed-in commenters.</span>
                    </p>

                    <p class="lp-field">
                        <label for="comments-filter-ip">IP address</label>
                        <input type="text" id="comments-filter-ip" name="ip" value="<?= esc_attr($ipFilter) ?>" placeholder="203.0.113.1">
                    </p>

                    <p class="lp-field">
                        <label for="comments-filter-date-from">From</label>
                        <input type="date" id="comments-filter-date-from" name="date_from" value="<?= esc_attr($dateFromFilter) ?>">
                    </p>

                    <p class="lp-field">
                        <label for="comments-filter-date-to">To</label>
                        <input type="date" id="comments-filter-date-to" name="date_to" value="<?= esc_attr($dateToFilter) ?>">
                    </p>

                    <button type="submit" class="lp-button lp-button--secondary">Filter</button>
                    <?php if ($filterQuery !== []): ?>
                        <a class="lp-button lp-button--link" href="<?= esc_url(admin_url('comments')) ?><?= $statusFilter !== null ? '?status=' . esc_attr($statusFilter->value) : ($reportedOnly ? '?reported=1' : '') ?>">Clear filters</a>
                    <?php endif; ?>
                </form>
            </div>
        </details>
    </section>

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
            <form method="post" action="<?= esc_url(admin_url('comments') . $currentViewQueryString) ?>" data-lp-bulk-form>
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
                                    <?php if ($comment->ipAddress !== null && $comment->ipAddress !== ''): ?>
                                        <br><span class="lp-field__hint"><?= esc_html($comment->ipAddress) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= esc_url(admin_url('comments')) ?>?action=edit&id=<?= (int) $comment->id ?>">
                                        <?= esc_html(mb_strimwidth($comment->content, 0, 80, '…')) ?>
                                    </a>
                                    <?php if (isset($reportSummaries[$comment->id])): ?>
                                        <?php
                                        $reportParts = [];

                                        foreach ($reportSummaries[$comment->id] as $reason => $reasonCount) {
                                            $reportParts[] = (CommentReportService::REASONS[$reason] ?? $reason) . ' (' . $reasonCount . ')';
                                        }
                                        ?>
                                        <br><span class="lp-comment-reports">&#9873; Reported: <?= esc_html(implode(', ', $reportParts)) ?></span>
                                    <?php endif; ?>
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
                                    <?php // The list query already tells us whether the post/page still exists (no slug when it's gone), so no per-row lookup. ?>
                                    <?php if (in_array($comment->status, [CommentStatus::Approved, CommentStatus::Pending], true) && $row['contentSlug'] !== ''): ?>
                                        <a class="lp-button lp-button--link" href="<?= esc_url(admin_url('comments') . '?action=reply&id=' . (int) $comment->id) ?>">Reply</a>
                                    <?php endif; ?>
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
                                    <?php if (isset($reportSummaries[$comment->id])): ?>
                                        <?php $dismissFormId = 'comment-dismiss-form-' . $comment->id; ?>
                                        <span class="lp-admin__inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= esc_attr(Csrf::token('comment_dismiss_reports_' . $comment->id)) ?>" form="<?= esc_attr($dismissFormId) ?>">
                                            <input type="hidden" name="form" value="dismiss_reports" form="<?= esc_attr($dismissFormId) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $comment->id ?>" form="<?= esc_attr($dismissFormId) ?>">
                                            <button type="submit" class="lp-button lp-button--link" form="<?= esc_attr($dismissFormId) ?>" title="Keep the comment and clear its reports">Dismiss reports</button>
                                        </span>
                                    <?php endif; ?>
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
            // Out-of-band target forms for each row action button above —
            // a nested <form> can't be used since the bulk-action form
            // already wraps the whole table.
            foreach ($pagination['comments'] as $row):
                $comment = $row['comment'];

                foreach (CommentStatus::cases() as $targetStatus):
                    if ($comment->status === $targetStatus) {
                        continue;
                    }
                    ?>
                    <form id="comment-moderate-form-<?= (int) $comment->id ?>-<?= esc_attr($targetStatus->value) ?>" method="post" action="<?= esc_url(admin_url('comments') . $currentViewQueryString) ?>"></form>
                    <?php
                endforeach;
                ?>
                <form id="comment-delete-form-<?= (int) $comment->id ?>" method="post" action="<?= esc_url(admin_url('comments') . $currentViewQueryString) ?>"></form>
                <?php if (isset($reportSummaries[$comment->id])): ?>
                    <form id="comment-dismiss-form-<?= (int) $comment->id ?>" method="post" action="<?= esc_url(admin_url('comments') . $currentViewQueryString) ?>"></form>
                <?php endif; ?>
                <?php
            endforeach;
            ?>

            <?php render_pagination($pagination, 'Comments pagination'); ?>
        <?php endif; ?>
    </section>
        </div>

        <?php
        // Computed unconditionally, not just while this tab is active —
        // matches the existing Settings > Security tab pattern (both
        // panels' data is always available server-side; admin-tabs.js
        // just toggles which is visible, no page reload needed).
        $statsAllowedRanges = [7, 30, 90];
        $statsRange = (int) ($_GET['stats_range'] ?? 30);

        if (!in_array($statsRange, $statsAllowedRanges, true)) {
            $statsRange = 30;
        }

        $statsLimit = 10;
        $dailyTotals = $commentService->dailyTotals($statsRange);
        $topCommenters = $commentService->topCommenters($statsRange, $statsLimit);
        $topCommentedContent = $commentService->topCommentedContent($statsRange, $statsLimit);
        $maxDailyComments = max(1, ...array_map(static fn (array $row): int => $row['count'], $dailyTotals !== [] ? $dailyTotals : [['count' => 0]]));
        ?>
        <div class="lp-tabs__panel" id="lp-tabpanel-statistics" role="tabpanel" aria-labelledby="lp-tab-statistics"<?= $activeTab === 'statistics' ? '' : ' hidden' ?>>
            <div class="lp-stats__cards">
                <div class="lp-stats__card">
                    <span class="lp-stats__card-value"><?= (int) $statusCounts[CommentStatus::Pending->value] ?></span>
                    <span class="lp-stats__card-label">Pending</span>
                </div>
                <div class="lp-stats__card">
                    <span class="lp-stats__card-value"><?= (int) $statusCounts[CommentStatus::Approved->value] ?></span>
                    <span class="lp-stats__card-label">Approved</span>
                </div>
                <div class="lp-stats__card">
                    <span class="lp-stats__card-value"><?= (int) $statusCounts[CommentStatus::Spam->value] ?></span>
                    <span class="lp-stats__card-label">Spam</span>
                </div>
                <div class="lp-stats__card">
                    <span class="lp-stats__card-value"><?= (int) $statusCounts[CommentStatus::Trash->value] ?></span>
                    <span class="lp-stats__card-label">Trash</span>
                </div>
            </div>

            <div class="lp-stats__range-toggle">
                <?php foreach ($statsAllowedRanges as $rangeOption): ?>
                    <a
                        href="<?= esc_url(admin_url('comments')) ?>?tab=statistics&stats_range=<?= (int) $rangeOption ?>"
                        class="lp-stats__range-toggle-option<?= $statsRange === $rangeOption ? ' is-active' : '' ?>"
                    >Last <?= (int) $rangeOption ?> Days</a>
                <?php endforeach; ?>
            </div>

            <section class="lp-admin__panel">
                <h2>Comments Over Time</h2>
                <?php if (array_sum(array_column($dailyTotals, 'count')) === 0): ?>
                    <p class="lp-admin__widget-placeholder">No comments in this range.</p>
                <?php else: ?>
                    <div class="lp-stats__chart">
                        <?php foreach ($dailyTotals as $day): ?>
                            <div class="lp-stats__bar-row">
                                <span class="lp-stats__bar-label"><?= esc_html(date('M j', strtotime($day['date']))) ?></span>
                                <span class="lp-stats__bar-track">
                                    <span class="lp-stats__bar-fill" data-style-width="<?= (int) round($day['count'] / $maxDailyComments * 100) ?>%"></span>
                                </span>
                                <span class="lp-stats__bar-value"><?= (int) $day['count'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <div class="lp-admin__grid">
                <section class="lp-admin__panel">
                    <h2>Top Commenters</h2>
                    <?php if ($topCommenters === []): ?>
                        <p class="lp-admin__widget-placeholder">No comments in this range.</p>
                    <?php else: ?>
                        <ul class="lp-admin__meta-list">
                            <?php foreach ($topCommenters as $commenterRow): ?>
                                <li>
                                    <span><?= esc_html($commenterRow['name']) ?> <span class="lp-field__hint">&lt;<?= esc_html($commenterRow['email']) ?>&gt;</span></span>
                                    <span><?= (int) $commenterRow['count'] ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

                <section class="lp-admin__panel">
                    <h2>Most Commented</h2>
                    <?php if ($topCommentedContent === []): ?>
                        <p class="lp-admin__widget-placeholder">No comments in this range.</p>
                    <?php else: ?>
                        <ul class="lp-admin__meta-list">
                            <?php foreach ($topCommentedContent as $contentRow): ?>
                                <?php
                                if ($contentRow['pageId'] !== null) {
                                    $statsContent = $kernel->pages->findById($contentRow['pageId']);
                                    $statsLink = $statsContent !== null ? page_permalink($statsContent) : null;
                                } else {
                                    $statsContent = $contentRow['postId'] !== null ? $kernel->posts->findById($contentRow['postId']) : null;
                                    $statsLink = $statsContent !== null ? post_permalink($statsContent) : null;
                                }

                                if ($statsContent === null) {
                                    continue;
                                }
                                ?>
                                <li>
                                    <span><a href="<?= esc_url($statsLink) ?>#comments"><?= esc_html($statsContent->title) ?></a></span>
                                    <span><?= (int) $contentRow['count'] ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </div>
<?php endif; ?>
