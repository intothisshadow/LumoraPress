<?php

/**
 * The admin Notifications screen: the signed-in user's in-app notifications and watched comment threads.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.18.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */
/** @var \LumoraPress\Services\NotificationService $notifications */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Services\CommentSubscriptionService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$subscriptions = new CommentSubscriptionService($kernel->database, (string) $kernel->config->get('table_prefix', 'lp_'));
$unreadOnly = ($_GET['filter'] ?? '') === 'unread';
$screenUrl = admin_url('notifications') . ($unreadOnly ? '?filter=unread' : '');

// Opening a notification is a plain link so it behaves like one (new tab,
// copy link); marking your own notification read is harmless as a GET.
if (isset($_GET['open'])) {
    $openId = (int) $_GET['open'];
    $notification = $notifications->findForUser($openId, $currentUser->id);
    $notifications->markRead($openId, $currentUser->id);
    $target = $notification !== null ? (string) $notification['url'] : '';

    // Absolute http(s) or site-relative links only — never "//host" or javascript: URLs.
    $isFollowable = preg_match('#^https?://#i', $target) === 1 || preg_match('#^/(?!/)#', $target) === 1;
    header('Location: ' . ($isFollowable ? $target : $screenUrl));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
    $id = (int) ($_POST['id'] ?? 0);

    if ($form === 'mark_read' && Csrf::verify('notifications', $token)) {
        $notifications->markRead($id, $currentUser->id);
    } elseif ($form === 'mark_all_read' && Csrf::verify('notifications', $token)) {
        $notifications->markAllRead($currentUser->id);
    } elseif ($form === 'delete_read' && Csrf::verify('notifications', $token)) {
        $notifications->deleteRead($currentUser->id);
    } elseif ($form === 'unwatch' && Csrf::verify('notifications', $token)) {
        $subscription = $subscriptions->find($id);

        // Only ever the viewer's own subscriptions.
        if ($subscription !== null && ((int) ($subscription['user_id'] ?? 0) === $currentUser->id || strcasecmp((string) $subscription['email'], $currentUser->email) === 0)) {
            $subscriptions->delete($id);
        }
    }

    header('Location: ' . $screenUrl);
    exit;
}

$notifications->pruneOldRead();

$pagination = $notifications->paginateForUser($currentUser->id, max(1, (int) ($_GET['paged'] ?? 1)), 20, $unreadOnly);
$unreadCount = $notifications->unreadCount($currentUser->id);
$totalCount = $notifications->paginateForUser($currentUser->id, 1, 1)['total'];

// One token shared by every form below: Csrf::field() per form would
// replace the token each time, leaving only the last form valid.
$csrfToken = Csrf::token('notifications');

$watchedThreads = [];

foreach ($subscriptions->activeForSubscriber($currentUser->id, $currentUser->email) as $subscription) {
    $content = $subscription['page_id'] !== null
        ? $kernel->pages->findById((int) $subscription['page_id'])
        : $kernel->posts->findById((int) $subscription['post_id']);

    if ($content !== null) {
        $watchedThreads[] = [
            'id' => (int) $subscription['id'],
            'title' => $content->title,
            'url' => $subscription['page_id'] !== null ? page_permalink($content) : post_permalink($content),
        ];
    }
}
?>
<h1 class="lp-admin__title">Notifications</h1>

<p class="lp-admin__filters">
    <a href="<?= esc_url(admin_url('notifications')) ?>" class="<?= !$unreadOnly ? 'is-active' : '' ?>">All (<?= (int) $totalCount ?>)</a>
    <a href="<?= esc_url(admin_url('notifications')) ?>?filter=unread" class="<?= $unreadOnly ? 'is-active' : '' ?>">Unread (<?= (int) $unreadCount ?>)</a>
</p>

<section class="lp-admin__panel">
    <?php if ($pagination['notifications'] === []): ?>
        <p class="lp-admin__widget-placeholder"><?= $unreadOnly ? 'You have no unread notifications.' : 'You have no notifications yet. You\'ll be notified here about replies to your comments and new comments on your posts and on threads you watch.' ?></p>
    <?php else: ?>
        <div class="lp-notifications__toolbar">
            <?php if ($unreadCount > 0): ?>
                <form method="post" action="<?= esc_url($screenUrl) ?>" class="lp-admin__inline-form">
                    <input type="hidden" name="csrf_token" value="<?= esc_attr($csrfToken) ?>">
                    <input type="hidden" name="form" value="mark_all_read">
                    <button type="submit" class="lp-button lp-button--secondary">Mark all as read</button>
                </form>
            <?php endif; ?>
            <?php if ($totalCount > $unreadCount): ?>
                <form method="post" action="<?= esc_url($screenUrl) ?>" class="lp-admin__inline-form" data-lp-confirm="Delete every notification you've already read?">
                    <input type="hidden" name="csrf_token" value="<?= esc_attr($csrfToken) ?>">
                    <input type="hidden" name="form" value="delete_read">
                    <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Clear read notifications</button>
                </form>
            <?php endif; ?>
        </div>

        <table class="lp-table lp-notifications">
            <thead>
                <tr>
                    <th scope="col">Notification</th>
                    <th scope="col">When</th>
                    <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pagination['notifications'] as $notification): ?>
                    <?php $isUnread = $notification['read_at'] === null; ?>
                    <tr class="lp-notifications__row<?= $isUnread ? ' is-unread' : '' ?>">
                        <td>
                            <?php if ($isUnread): ?><span class="lp-visually-hidden">Unread: </span><?php endif; ?>
                            <?php if ((string) $notification['url'] !== ''): ?>
                                <a class="lp-notifications__message" href="<?= esc_url(admin_url('notifications') . '?open=' . (int) $notification['id']) ?>"><?= esc_html((string) $notification['message']) ?></a>
                            <?php else: ?>
                                <span class="lp-notifications__message"><?= esc_html((string) $notification['message']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="lp-notifications__when"><time datetime="<?= esc_attr((new DateTimeImmutable((string) $notification['created_at']))->format(DATE_ATOM)) ?>"><?= esc_html(the_date(new DateTimeImmutable((string) $notification['created_at']))) ?> <?= esc_html(the_time(new DateTimeImmutable((string) $notification['created_at']))) ?></time></td>
                        <td class="lp-notifications__actions">
                            <?php if ($isUnread): ?>
                                <form method="post" action="<?= esc_url($screenUrl) ?>" class="lp-admin__inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= esc_attr($csrfToken) ?>">
                                    <input type="hidden" name="form" value="mark_read">
                                    <input type="hidden" name="id" value="<?= (int) $notification['id'] ?>">
                                    <button type="submit" class="lp-button lp-button--link">Mark as read</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php render_pagination($pagination, 'Notifications pagination'); ?>
    <?php endif; ?>
</section>

<?php if ($watchedThreads !== []): ?>
    <section class="lp-admin__panel">
        <h2>Watched Threads</h2>
        <p class="lp-field__hint">You'll be notified about new comments on these posts and pages.</p>
        <table class="lp-table">
            <tbody>
                <?php foreach ($watchedThreads as $thread): ?>
                    <tr>
                        <td><a href="<?= esc_url($thread['url']) ?>"><?= esc_html($thread['title']) ?></a></td>
                        <td>
                            <form method="post" action="<?= esc_url($screenUrl) ?>" class="lp-admin__inline-form">
                                <input type="hidden" name="csrf_token" value="<?= esc_attr($csrfToken) ?>">
                                <input type="hidden" name="form" value="unwatch">
                                <input type="hidden" name="id" value="<?= (int) $thread['id'] ?>">
                                <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Stop watching</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>
