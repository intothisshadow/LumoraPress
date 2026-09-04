<?php

/**
 * Discussion Settings email notifications for new/moderation-pending comments.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Mail\Mailer;
use LumoraPress\Core\PressConfig;
use LumoraPress\Models\Comment;
use LumoraPress\Models\CommentStatus;
use LumoraPress\Models\Page;
use LumoraPress\Models\Post;

/**
 * Sends the "Notify administrator of new comments" / "...when comments
 * require moderation" / "Notify post author" emails from Settings >
 * Discussion. Uses the existing Mailer interface rather than a new
 * transport, and admin_email (already the "From" address source for
 * password-reset mail) as the default administrator recipient.
 */
final class CommentNotificationService
{
    public function __construct(
        private readonly PressConfig $config,
        private readonly Mailer $mailer,
        private readonly UserService $users,
    ) {
    }

    public function notifyNewComment(Comment $comment, Post $post): void
    {
        $recipients = [];

        if ($this->config->option('comment_notify_admin_new', '1') !== '0') {
            $recipients[] = (string) $this->config->option('admin_email', '');
        }

        if ($comment->status === CommentStatus::Pending && $this->config->option('comment_notify_admin_moderation', '1') !== '0') {
            $recipients[] = (string) $this->config->option('admin_email', '');
        }

        if ($this->config->option('comment_notify_author', '0') === '1') {
            $author = $this->users->findById($post->authorId);

            if ($author !== null) {
                $recipients[] = $author->email;
            }
        }

        foreach ($this->parseRecipients((string) $this->config->option('comment_notify_recipients', '')) as $extra) {
            $recipients[] = $extra;
        }

        $recipients = array_values(array_unique(array_filter(
            array_map('trim', $recipients),
            static fn (string $email): bool => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));

        if ($recipients === []) {
            return;
        }

        $link = post_permalink($post) . '#comment-' . $comment->id;
        $subject = $comment->status === CommentStatus::Pending
            ? 'Comment awaiting moderation on "' . $post->title . '"'
            : 'New comment on "' . $post->title . '"';
        $body = $comment->guestName . " wrote:\n\n" . $comment->content . "\n\n" . $link;

        foreach ($recipients as $to) {
            $this->mailer->send($to, $subject, $body);
        }
    }

    /**
     * Mirrors notifyNewComment(Post) exactly, for Pages.
     */
    public function notifyNewCommentOnPage(Comment $comment, Page $page): void
    {
        $recipients = [];

        if ($this->config->option('comment_notify_admin_new', '1') !== '0') {
            $recipients[] = (string) $this->config->option('admin_email', '');
        }

        if ($comment->status === CommentStatus::Pending && $this->config->option('comment_notify_admin_moderation', '1') !== '0') {
            $recipients[] = (string) $this->config->option('admin_email', '');
        }

        if ($this->config->option('comment_notify_author', '0') === '1') {
            $author = $this->users->findById($page->authorId);

            if ($author !== null) {
                $recipients[] = $author->email;
            }
        }

        foreach ($this->parseRecipients((string) $this->config->option('comment_notify_recipients', '')) as $extra) {
            $recipients[] = $extra;
        }

        $recipients = array_values(array_unique(array_filter(
            array_map('trim', $recipients),
            static fn (string $email): bool => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));

        if ($recipients === []) {
            return;
        }

        $link = page_permalink($page) . '#comment-' . $comment->id;
        $subject = $comment->status === CommentStatus::Pending
            ? 'Comment awaiting moderation on "' . $page->title . '"'
            : 'New comment on "' . $page->title . '"';
        $body = $comment->guestName . " wrote:\n\n" . $comment->content . "\n\n" . $link;

        foreach ($recipients as $to) {
            $this->mailer->send($to, $subject, $body);
        }
    }

    /**
     * @return array<int, string>
     */
    private function parseRecipients(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $email): bool => $email !== ''));
    }
}
