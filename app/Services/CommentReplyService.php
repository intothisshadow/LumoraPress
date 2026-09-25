<?php

/**
 * Publishes a moderator's reply to a comment from the admin Comments screen.
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

use InvalidArgumentException;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\Security\IpAnonymizer;
use LumoraPress\Models\Comment;
use LumoraPress\Models\CommentStatus;
use LumoraPress\Models\User;

/**
 * A moderator's reply is trusted, so it skips everything a visitor's comment
 * goes through (keyword and link holds, flood control, Akismet) and is
 * approved outright. It then fires 'comment_posted' like a front-end comment,
 * so subscriber emails, in-app notices, and @mentions all still happen, and
 * sends the "someone replied to your comment" email when that setting is on.
 * The administrator is deliberately not emailed about their own reply.
 *
 * The exceptions thrown here carry messages meant to be shown to the admin.
 */
final class CommentReplyService
{
    public function __construct(
        private readonly CommentService $comments,
        private readonly PostService $posts,
        private readonly PageService $pages,
        private readonly CommentModerationService $moderation,
        private readonly CommentNotificationService $notifications,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    /**
     * Whether $comment can be replied to: only a comment a visitor can (or
     * once you approve it, will) see, on content that still exists.
     */
    public function canReplyTo(Comment $comment): bool
    {
        return in_array($comment->status, [CommentStatus::Approved, CommentStatus::Pending], true)
            && $this->contentExists($comment);
    }

    /**
     * A Pending parent is approved first: a reply under a comment nobody can
     * see would never display.
     *
     * @throws InvalidArgumentException when the text is empty or the comment can't be replied to
     */
    public function reply(Comment $parent, User $author, string $content, ?string $ipAddress = null, ?string $userAgent = null): Comment
    {
        $content = trim($content);

        if ($content === '') {
            throw new InvalidArgumentException('A reply cannot be empty.');
        }

        if (!$this->canReplyTo($parent)) {
            throw new InvalidArgumentException("That comment can't be replied to.");
        }

        if ($parent->status === CommentStatus::Pending) {
            $this->comments->updateStatus($parent->id, CommentStatus::Approved);
        }

        if ($ipAddress !== null && $this->moderation->isIpAnonymizationEnabled()) {
            $ipAddress = IpAnonymizer::anonymize($ipAddress);
        }

        $reply = $this->comments->create(
            postId: $parent->pageId === null ? $parent->postId : null,
            parentId: $parent->id,
            userId: $author->id,
            guestName: $author->displayName,
            guestEmail: $author->email,
            guestUrl: null,
            content: $content,
            status: CommentStatus::Approved,
            ipAddress: $ipAddress,
            userAgent: $userAgent !== null ? substr($userAgent, 0, 255) : null,
            pageId: $parent->pageId,
        );

        $this->hooks?->doAction('comment_posted', $reply);

        $this->sendReplyEmail($reply, $parent);

        return $reply;
    }

    private function sendReplyEmail(Comment $reply, Comment $parent): void
    {
        if ($parent->pageId !== null) {
            $page = $this->pages->findById($parent->pageId);

            if ($page !== null) {
                $this->notifications->notifyReplyOnPage($reply, $parent, $page);
            }

            return;
        }

        $post = $parent->postId !== null ? $this->posts->findById($parent->postId) : null;

        if ($post !== null) {
            $this->notifications->notifyReply($reply, $parent, $post);
        }
    }

    private function contentExists(Comment $comment): bool
    {
        if ($comment->pageId !== null) {
            return $this->pages->findById($comment->pageId) !== null;
        }

        return $comment->postId !== null && $this->posts->findById($comment->postId) !== null;
    }
}
