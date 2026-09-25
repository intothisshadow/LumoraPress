<?php

/**
 * Public endpoints for reacting to and reporting comments.
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
use LumoraPress\Models\Comment;
use LumoraPress\Models\CommentStatus;
use LumoraPress\Services\CommentFollowupService;
use LumoraPress\Services\CommentReactionService;
use LumoraPress\Services\CommentReportService;
use LumoraPress\Services\CommentService;
use LumoraPress\Services\PageService;
use LumoraPress\Services\PostService;

/**
 * Both actions work as plain form posts (redirecting back to the comment)
 * and as fetch() calls from assets/js/comments.js, which send
 * "X-Requested-With: fetch" and get JSON back — including a fresh CSRF
 * token, since a successful Csrf::verify() consumes the one on the page.
 */
final class CommentInteractionController
{
    public const CSRF_ACTION = 'comment_interact';

    /** Random per-browser identifier for guests; only its hash is stored. */
    public const VOTER_COOKIE = 'lp_voter';

    public function __construct(
        private readonly CommentService $comments,
        private readonly PostService $posts,
        private readonly PageService $pages,
        private readonly CommentReactionService $reactions,
        private readonly CommentReportService $reports,
        private readonly CommentFollowupService $followups,
        private readonly Auth $auth,
        private readonly SiteController $site,
    ) {
    }

    /**
     * The current visitor's voter/reporter key, or null for a guest who
     * has never reacted or reported (and so has no cookie yet).
     */
    public function currentVoterKey(): ?string
    {
        $user = $this->auth->user();

        if ($user !== null) {
            return CommentReactionService::userVoterKey($user->id);
        }

        $cookie = $_COOKIE[self::VOTER_COOKIE] ?? null;

        return is_string($cookie) && preg_match('/^[a-f0-9]{64}$/', $cookie) === 1 ? CommentReactionService::guestVoterKey($cookie) : null;
    }

    /**
     * @param array<string, string> $params
     */
    public function react(array $params): void
    {
        $comment = $this->comments->findById((int) ($params['id'] ?? 0));
        $url = $comment !== null ? $this->commentUrl($comment) : null;

        if ($comment === null || $url === null || !$this->reactions->isEnabled()) {
            $this->site->notFound();

            return;
        }

        $user = $this->auth->user();

        if (!$this->verifyCsrf() || ($user === null && $this->reactions->requiresLogin())) {
            $this->respond($url, $comment->id, ['ok' => false]);

            return;
        }

        $reaction = is_string($_POST['reaction'] ?? null) ? $_POST['reaction'] : '';
        $choice = $this->reactions->toggle($comment->id, $reaction, $this->ensureVoterKey(), $user?->id);

        $this->respond($url, $comment->id, [
            'ok' => true,
            'choice' => $choice,
            'counts' => $this->reactions->countsFor([$comment->id])[$comment->id] ?? [],
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function report(array $params): void
    {
        $comment = $this->comments->findById((int) ($params['id'] ?? 0));
        $url = $comment !== null ? $this->commentUrl($comment) : null;

        if ($comment === null || $url === null || !$this->reports->isEnabled()) {
            $this->site->notFound();

            return;
        }

        if (!$this->verifyCsrf()) {
            $this->respond($url, $comment->id, ['ok' => false]);

            return;
        }

        $reason = is_string($_POST['reason'] ?? null) ? $_POST['reason'] : '';
        $isNew = $this->reports->report($comment->id, $reason, $this->ensureVoterKey(), $this->auth->user()?->id);

        if ($isNew) {
            $count = $this->reports->countFor($comment->id);
            $threshold = $this->reports->threshold();
            $held = $threshold > 0 && $count >= $threshold && $comment->status === CommentStatus::Approved;

            if ($held) {
                $this->comments->updateStatus($comment->id, CommentStatus::Pending);
            }

            // Moderators hear about the first report, and again if it gets the comment held.
            if ($count === 1 || $held) {
                $this->followups->notifyModeratorsOfReport($comment, $held);
            }
        }

        $this->respond($url . '?comment_reported=' . $comment->id, $comment->id, ['ok' => true, 'reported' => true]);
    }

    private function verifyCsrf(): bool
    {
        return Csrf::verify(self::CSRF_ACTION, is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null);
    }

    /**
     * Only approved comments on publicly visible posts/pages can be
     * reacted to or reported — never a held, spam, or private one.
     */
    private function commentUrl(Comment $comment): ?string
    {
        if ($comment->status !== CommentStatus::Approved) {
            return null;
        }

        if ($comment->pageId !== null) {
            $page = $this->pages->findById($comment->pageId);

            return $page !== null && $page->isPubliclyVisible() ? page_permalink($page) : null;
        }

        $post = $comment->postId !== null ? $this->posts->findById($comment->postId) : null;

        return $post !== null && $post->isPubliclyVisible() ? post_permalink($post) : null;
    }

    private function ensureVoterKey(): string
    {
        $key = $this->currentVoterKey();

        if ($key !== null) {
            return $key;
        }

        $token = bin2hex(random_bytes(32));
        $_COOKIE[self::VOTER_COOKIE] = $token;
        setcookie(self::VOTER_COOKIE, $token, [
            'expires' => time() + 86400 * 365,
            'path' => '/',
            'secure' => str_starts_with(home_url(), 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return CommentReactionService::guestVoterKey($token);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function respond(string $url, int $commentId, array $payload): void
    {
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode($payload + ['csrf_token' => Csrf::token(self::CSRF_ACTION)], JSON_THROW_ON_ERROR);
            exit;
        }

        header('Location: ' . $url . '#comment-' . $commentId, true, 303);
        exit;
    }
}
