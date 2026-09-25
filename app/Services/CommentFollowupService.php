<?php

/**
 * Thread-subscriber emails and in-app notifications that follow a comment being posted or approved.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.18.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Mail\Mailer;
use LumoraPress\Core\PressConfig;
use LumoraPress\Models\Comment;
use LumoraPress\Models\CommentStatus;
use LumoraPress\Models\User;

/**
 * Everything here waits for a comment to be approved — at submission
 * when it's approved immediately, otherwise when a moderator approves it
 * later — so held or spam comments never reach subscribers or users'
 * inboxes. The exceptions are the "awaiting moderation" and "reported"
 * notices, which only go to users who can moderate. CommentNotificationService keeps
 * its own, separately configured admin/author/reply emails.
 */
final class CommentFollowupService
{
    public function __construct(
        private readonly PressConfig $config,
        private readonly Mailer $mailer,
        private readonly CommentService $comments,
        private readonly PostService $posts,
        private readonly PageService $pages,
        private readonly UserService $users,
        private readonly CommentSubscriptionService $subscriptions,
        private readonly NotificationService $notifications,
    ) {
    }

    public function subscriptionsEnabled(): bool
    {
        return $this->config->option('comment_subscriptions_enabled', '0') === '1';
    }

    /**
     * Handles the comment form's "Notify me of new comments" checkbox. A
     * signed-in user's subscription is active straight away (it's their own
     * account email); a guest's waits for them to confirm it by email.
     *
     * @return array<string, mixed>|null The subscription, or null when none was created.
     */
    public function subscribeCommenter(Comment $comment, ?User $user): ?array
    {
        if (!$this->subscriptionsEnabled() || $comment->status === CommentStatus::Spam) {
            return null;
        }

        $target = self::target($comment);

        if ($target === null || !$this->isRealEmail($comment->guestEmail)) {
            return null;
        }

        return $this->subscriptions->subscribe(
            $target[0],
            $target[1],
            $comment->guestEmail,
            $user?->id,
            $user !== null,
            $comment->id,
        );
    }

    public function onCommentPosted(Comment $comment): void
    {
        if ($comment->status === CommentStatus::Pending) {
            $this->notifyModerators($comment);
        } elseif ($comment->status === CommentStatus::Approved) {
            $this->dispatch($comment);
        }
    }

    public function onStatusChanged(Comment $comment): void
    {
        if ($comment->status === CommentStatus::Approved) {
            $this->dispatch($comment);
        }
    }

    public static function manageUrl(string $token): string
    {
        return home_url('comment-subscription/' . $token);
    }

    private function dispatch(Comment $comment): void
    {
        $context = $this->resolveContent($comment);

        if ($context === null || !$this->comments->claimFollowup($comment->id)) {
            return;
        }

        $parent = $comment->parentId !== null ? $this->comments->findById($comment->parentId) : null;

        if ($this->subscriptionsEnabled()) {
            $this->sendConfirmations($comment, $context);
            $this->emailSubscribers($comment, $parent, $context);
        }

        $this->notifyUsers($comment, $parent, $context);
    }

    /**
     * @param array{type: string, id: int, title: string, url: string, authorId: int} $context
     */
    private function sendConfirmations(Comment $comment, array $context): void
    {
        foreach ($this->subscriptions->pendingForSourceComment($comment->id) as $subscription) {
            $subject = sprintf(__('Confirm your subscription to comments on "%s"'), $context['title']);
            $body = sprintf(__('You asked to be emailed when someone comments on "%s".'), $context['title']) . "\n\n"
                . __('Confirm your subscription here:') . "\n" . self::manageUrl((string) $subscription['token']) . "\n\n"
                . __("If you didn't ask for this, just ignore this email. You won't be subscribed and won't hear from us again.");

            $this->mailer->send((string) $subscription['email'], $subject, $body);
        }
    }

    /**
     * @param array{type: string, id: int, title: string, url: string, authorId: int} $context
     */
    private function emailSubscribers(Comment $comment, ?Comment $parent, array $context): void
    {
        // The parent's author already gets a dedicated reply email when that's switched on.
        $skipParent = $parent !== null && $this->config->option('comment_notify_on_reply', '0') === '1';
        $link = $context['url'] . '#comment-' . $comment->id;

        foreach ($this->subscriptions->activeFor($context['type'], $context['id']) as $subscription) {
            $email = (string) $subscription['email'];

            if (strcasecmp($email, $comment->guestEmail) === 0 || ($skipParent && strcasecmp($email, $parent->guestEmail) === 0)) {
                continue;
            }

            $subject = sprintf(__('New comment on "%s"'), $context['title']);
            $body = sprintf(__('%s wrote:'), $comment->guestName) . "\n\n" . $comment->content . "\n\n" . $link . "\n\n"
                . "-- \n"
                . sprintf(__('You are receiving this because you subscribed to comments on "%s".'), $context['title']) . "\n"
                . __('Unsubscribe:') . ' ' . self::manageUrl((string) $subscription['token']);

            $this->mailer->send($email, $subject, $body);
        }
    }

    /**
     * One notification per user per comment, even when a user is the
     * parent commenter, the post author, and a subscriber all at once —
     * the most specific reason wins.
     *
     * @param array{type: string, id: int, title: string, url: string, authorId: int} $context
     */
    private function notifyUsers(Comment $comment, ?Comment $parent, array $context): void
    {
        $link = $context['url'] . '#comment-' . $comment->id;
        $messages = [];

        if ($parent?->userId !== null) {
            $messages[$parent->userId] = ['comment_reply', sprintf(__('%1$s replied to your comment on "%2$s"'), $comment->guestName, $context['title'])];
        }

        foreach ($this->mentionedUserIds($comment->content) as $mentionedId) {
            $messages[$mentionedId] ??= ['comment_mention', sprintf(__('%1$s mentioned you in a comment on "%2$s"'), $comment->guestName, $context['title'])];
        }

        $messages[$context['authorId']] ??= [
            'comment_on_your_content',
            sprintf($context['type'] === 'page' ? __('%1$s commented on your page "%2$s"') : __('%1$s commented on your post "%2$s"'), $comment->guestName, $context['title']),
        ];

        $watchers = $this->subscriptionsEnabled() ? $this->subscriptions->activeFor($context['type'], $context['id']) : [];

        foreach ($watchers as $subscription) {
            if ($subscription['user_id'] !== null) {
                $messages[(int) $subscription['user_id']] ??= ['comment_on_watched', sprintf(__('%1$s commented on "%2$s", a thread you\'re watching'), $comment->guestName, $context['title'])];
            }
        }

        unset($messages[$comment->userId ?? 0]);

        foreach ($messages as $userId => [$type, $message]) {
            if ($userId > 0 && $this->users->findById($userId) !== null) {
                $this->notifications->notify($userId, $type, $message, $link);
            }
        }
    }

    /**
     * Visitors reported $comment; $held is true when the reports just moved
     * it back to Pending.
     */
    public function notifyModeratorsOfReport(Comment $comment, bool $held): void
    {
        $context = $this->resolveContent($comment);

        if ($context === null) {
            return;
        }

        $message = $held
            ? sprintf(__('A comment from %1$s on "%2$s" was reported by several visitors and is now held for moderation'), $comment->guestName, $context['title'])
            : sprintf(__('A comment from %1$s on "%2$s" was reported'), $comment->guestName, $context['title']);

        $this->notifyEveryModerator($comment, 'comment_reported', $message, admin_url('comments') . '?reported=1');
    }

    private function notifyModerators(Comment $comment): void
    {
        $context = $this->resolveContent($comment);

        if ($context === null) {
            return;
        }

        $message = sprintf(__('Comment from %1$s on "%2$s" is awaiting moderation'), $comment->guestName, $context['title']);

        $this->notifyEveryModerator($comment, 'comment_moderation', $message, admin_url('comments') . '?status=' . CommentStatus::Pending->value);
    }

    private function notifyEveryModerator(Comment $comment, string $type, string $message, string $url): void
    {
        foreach ($this->users->listAll() as $user) {
            if ($user->id !== $comment->userId && $user->can('moderate_comments')) {
                $this->notifications->notify($user->id, $type, $message, $url);
            }
        }
    }

    /**
     * Users named by @author-slug in $content — the same public slug their
     * author archive uses, never a login name. Capped so one comment can't
     * fan out into an unbounded number of notifications.
     *
     * @return array<int, int>
     */
    private function mentionedUserIds(string $content): array
    {
        $ids = [];

        foreach (array_slice(array_unique(comment_mentions($content)), 0, 10) as $slug) {
            $user = $this->users->findByAuthorSlug($slug);

            if ($user !== null) {
                $ids[] = $user->id;
            }
        }

        return $ids;
    }

    /**
     * @return array{type: string, id: int, title: string, url: string, authorId: int}|null
     */
    private function resolveContent(Comment $comment): ?array
    {
        if ($comment->pageId !== null) {
            $page = $this->pages->findById($comment->pageId);

            return $page === null ? null : ['type' => 'page', 'id' => $page->id, 'title' => $page->title, 'url' => page_permalink($page), 'authorId' => $page->authorId];
        }

        $post = $comment->postId !== null ? $this->posts->findById($comment->postId) : null;

        return $post === null ? null : ['type' => 'post', 'id' => $post->id, 'title' => $post->title, 'url' => post_permalink($post), 'authorId' => $post->authorId];
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private static function target(Comment $comment): ?array
    {
        if ($comment->pageId !== null) {
            return ['page', $comment->pageId];
        }

        return $comment->postId !== null ? ['post', $comment->postId] : null;
    }

    /**
     * Excludes the placeholder address a guest gets when Settings >
     * Discussion doesn't require an email — nobody reads that inbox.
     */
    private function isRealEmail(string $email): bool
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $placeholderHost = (string) (parse_url(home_url(), PHP_URL_HOST) ?: 'invalid.example');

        return strcasecmp($email, 'anonymous@' . $placeholderHost) !== 0;
    }
}
