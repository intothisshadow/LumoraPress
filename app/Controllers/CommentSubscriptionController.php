<?php

/**
 * Public endpoints for confirming, cancelling, and watching comment-thread subscriptions.
 *
 * @package LumoraPress
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.18.0
 */

declare(strict_types=1);

namespace LumoraPress\Controllers;

use LumoraPress\Core\Security\Auth;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\Page;
use LumoraPress\Models\Post;
use LumoraPress\Models\User;
use LumoraPress\Services\CommentFollowupService;
use LumoraPress\Services\CommentSubscriptionService;
use LumoraPress\Services\PageService;
use LumoraPress\Services\PostService;

/**
 * An emailed link never changes anything by itself: opening it only
 * remembers the token in the session and sends the reader to the thread,
 * where comment_subscription_panel() shows a button that POSTs back here.
 * Mail scanners that prefetch every link therefore can't confirm or
 * cancel a subscription on someone's behalf, and the token never appears
 * in a URL a cache or Referer header could pick up.
 */
final class CommentSubscriptionController
{
    public const SESSION_KEY = 'lp_comment_subscription_token';

    public const CSRF_ACTION = 'comment_subscription';

    private const FLASH_KEY = 'lp_comment_subscription_flash';

    public function __construct(
        private readonly CommentSubscriptionService $subscriptions,
        private readonly CommentFollowupService $followups,
        private readonly PostService $posts,
        private readonly PageService $pages,
        private readonly Auth $auth,
        private readonly SiteController $site,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function open(array $params): void
    {
        $subscription = $this->subscriptions->findByToken((string) ($params['token'] ?? ''));
        $url = $subscription !== null ? $this->threadUrl($subscription) : null;

        if ($subscription === null || $url === null) {
            $this->site->notFound();

            return;
        }

        $_SESSION[self::SESSION_KEY] = (string) $subscription['token'];

        $this->redirect($url . '?lp_subscription=manage#lp-comment-subscription');
    }

    /**
     * @param array<string, string> $params
     */
    public function update(array $params): void
    {
        $action = is_string($_POST['subscription_action'] ?? null) ? $_POST['subscription_action'] : '';
        $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (in_array($action, ['watch', 'unwatch'], true)) {
            $this->updateWatch($action, $csrfToken);

            return;
        }

        $token = is_string($_SESSION[self::SESSION_KEY] ?? null) ? $_SESSION[self::SESSION_KEY] : '';
        $subscription = $this->subscriptions->findByToken($token);
        $url = $subscription !== null ? $this->threadUrl($subscription) : null;

        if ($subscription === null || $url === null) {
            $this->redirect(home_url());

            return;
        }

        if (!Csrf::verify(self::CSRF_ACTION, $csrfToken)) {
            $this->redirect($url . '?lp_subscription=manage#lp-comment-subscription');

            return;
        }

        $result = match ($action) {
            'confirm' => $this->subscriptions->confirm((int) $subscription['id']) ? 'confirmed' : 'error',
            'unsubscribe' => $this->subscriptions->delete((int) $subscription['id']) ? 'unsubscribed' : 'error',
            default => 'error',
        };

        unset($_SESSION[self::SESSION_KEY]);

        $this->redirect($url . '?lp_subscription=' . $result . '#lp-comment-subscription');
    }

    /**
     * Signed-in users can watch a thread without commenting on it; their
     * account email needs no confirmation.
     */
    private function updateWatch(string $action, ?string $csrfToken): void
    {
        $user = $this->auth->user();
        $contentType = ($_POST['content_type'] ?? '') === 'page' ? 'page' : 'post';
        $contentId = (int) ($_POST['content_id'] ?? 0);
        $url = $this->threadUrl([$contentType . '_id' => $contentId]);

        if ($url === null) {
            $this->redirect(home_url());

            return;
        }

        if ($user === null || !$this->followups->subscriptionsEnabled() || !Csrf::verify(self::CSRF_ACTION, $csrfToken)) {
            $this->redirect($url . '#comments');

            return;
        }

        if ($action === 'watch') {
            $this->subscriptions->subscribe($contentType, $contentId, $user->email, $user->id, true);
            $result = 'watching';
        } else {
            $existing = $this->subscriptions->findFor($contentType, $contentId, $user->email);

            if ($existing !== null) {
                $this->subscriptions->delete((int) $existing['id']);
            }

            $result = 'unsubscribed';
        }

        $this->redirect($url . '?lp_subscription=' . $result . '#lp-comment-subscription');
    }

    /**
     * What comment_subscription_panel() should show above $content's
     * comments for this request, or null when there's nothing to show.
     *
     * @return array{notice: ?string, manage: ?array{status: string}, watch: ?array{watching: bool, contentType: string, contentId: int}, formAction: string}|null
     */
    public function panelState(Post|Page $content, ?User $user): ?array
    {
        if (!$this->followups->subscriptionsEnabled()) {
            return null;
        }

        $contentType = $content instanceof Page ? 'page' : 'post';
        $flag = is_string($_GET['lp_subscription'] ?? null) ? $_GET['lp_subscription'] : null;
        $notice = in_array($flag, ['confirmed', 'unsubscribed', 'watching', 'error'], true) ? $flag : null;

        $flash = $_SESSION[self::FLASH_KEY] ?? null;

        if (is_array($flash) && ($flash['contentType'] ?? null) === $contentType && ($flash['contentId'] ?? null) === $content->id) {
            $notice = (string) $flash['notice'];
            unset($_SESSION[self::FLASH_KEY]);
        }

        $manage = null;

        if ($flag === 'manage') {
            $subscription = $this->subscriptions->findByToken(is_string($_SESSION[self::SESSION_KEY] ?? null) ? $_SESSION[self::SESSION_KEY] : '');

            if ($subscription !== null && (int) ($subscription[$contentType . '_id'] ?? 0) === $content->id) {
                $manage = ['status' => (string) $subscription['status']];
            } else {
                $notice = 'error';
            }
        }

        $watch = null;

        if ($user !== null && $manage === null) {
            $watch = [
                'watching' => ($this->subscriptions->findFor($contentType, $content->id, $user->email)['status'] ?? null) === CommentSubscriptionService::STATUS_ACTIVE,
                'contentType' => $contentType,
                'contentId' => $content->id,
            ];
        }

        return ['notice' => $notice, 'manage' => $manage, 'watch' => $watch, 'formAction' => home_url('comment-subscription')];
    }

    /**
     * One-time notice for a guest who just subscribed from the comment
     * form, shown the next time that thread's comments render.
     */
    public static function flashSubscribed(string $contentType, int $contentId, bool $confirmationSent): void
    {
        $_SESSION[self::FLASH_KEY] = [
            'contentType' => $contentType,
            'contentId' => $contentId,
            'notice' => $confirmationSent ? 'confirm_sent' : 'confirm_after_approval',
        ];
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function threadUrl(array $subscription): ?string
    {
        if (($subscription['page_id'] ?? null) !== null) {
            $page = $this->pages->findById((int) $subscription['page_id']);

            return $page !== null && $page->isPubliclyVisible() ? page_permalink($page) : null;
        }

        $post = ($subscription['post_id'] ?? null) !== null ? $this->posts->findById((int) $subscription['post_id']) : null;

        return $post !== null && $post->isPubliclyVisible() ? post_permalink($post) : null;
    }

    private function redirect(string $url): void
    {
        header('Location: ' . $url, true, 303);
        exit;
    }
}
