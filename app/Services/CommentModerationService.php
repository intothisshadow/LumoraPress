<?php

/**
 * Discussion Settings (LP-047) moderation policy: comment status decisions, comment-open eligibility, and field requirements.
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

use DateTimeImmutable;
use LumoraPress\Core\PressConfig;
use LumoraPress\Models\CommentStatus;
use LumoraPress\Models\Page;
use LumoraPress\Models\Post;

/**
 * Centralizes the Settings > Discussion decision logic that
 * SiteController::submitComment() and ApiController::commentsStore() both
 * need, so the two entry points can't drift the way the pre-LP-047 status
 * calculation (duplicated inline in each controller) already had.
 *
 * Deliberately separate from CommentService: CommentService is CRUD/query
 * only (see its own docblock), this is policy that reads PressConfig.
 */
final class CommentModerationService
{
    public function __construct(
        private readonly PressConfig $config,
        private readonly CommentService $comments,
    ) {
    }

    /**
     * A post accepts comments only if the post itself and the site as a
     * whole allow it, and — if "Automatically close comments after N
     * days" is configured — the post isn't older than that window yet.
     */
    public function commentsOpenFor(Post $post): bool
    {
        if (!$post->commentsOpen || $this->config->option('comments_enabled', '1') === '0') {
            return false;
        }

        $closeAfterDays = (int) $this->config->option('comment_close_after_days', '0');

        if ($closeAfterDays <= 0) {
            return true;
        }

        $reference = $post->publishedAt ?? $post->createdAt;

        return $reference->modify('+' . $closeAfterDays . ' days') > new DateTimeImmutable();
    }

    /**
     * Mirrors commentsOpenFor(Post) exactly, for Pages. A separate
     * overload rather than a Post|Page union parameter since PHP has no
     * common interface between the two models to read commentsOpen/
     * publishedAt/createdAt off of generically.
     */
    public function commentsOpenForPage(Page $page): bool
    {
        if (!$page->commentsOpen || $this->config->option('comments_enabled', '1') === '0') {
            return false;
        }

        $closeAfterDays = (int) $this->config->option('comment_close_after_days', '0');

        if ($closeAfterDays <= 0) {
            return true;
        }

        $reference = $page->publishedAt ?? $page->createdAt;

        return $reference->modify('+' . $closeAfterDays . ' days') > new DateTimeImmutable();
    }

    public function requiresRegistrationToComment(): bool
    {
        return $this->config->option('comment_require_registration', '0') === '1';
    }

    public function isAuthorNameRequired(): bool
    {
        return $this->config->option('comment_author_name_required', '1') !== '0';
    }

    public function isAuthorEmailRequired(): bool
    {
        return $this->config->option('comment_author_email_required', '1') !== '0';
    }

    /**
     * The default "Allow comments" state for a brand-new post (Settings >
     * Discussion's "Allow comments on new posts") — only consulted when
     * creating a post, never overrides an existing post's own toggle.
     * Pages' new-page default reuses this exact same option rather than
     * a separate "new pages" setting — Settings > Discussion has no
     * Pages-specific fields, only a Posts-shaped set that Pages'
     * Discussion box in the admin editor borrows for this one default.
     */
    public function defaultCommentsOpenForNewPosts(): bool
    {
        return $this->config->option('comment_default_status_for_new_posts', 'open') !== 'closed';
    }

    /**
     * Decides the initial CommentStatus for a freshly submitted comment,
     * before any Akismet/spam-plugin check runs (those remain strictly
     * additive on top of this — see SiteController::submitComment()'s
     * docblock for why). Order of checks mirrors classic WordPress:
     * a disallowed-keyword match rejects outright regardless of trust;
     * everything else only ever holds a comment for moderation, it never
     * un-approves one that trust already earned.
     */
    public function determineStatus(
        ?int $userId,
        bool $isTrustedModerator,
        string $guestName,
        string $guestEmail,
        ?string $guestUrl,
        string $content,
    ): CommentStatus {
        if (!$isTrustedModerator && $this->matchesKeywordList($this->disallowedKeywords(), $guestName, $guestEmail, $guestUrl, $content)) {
            return CommentStatus::Spam;
        }

        $manualApprovalForAll = $this->config->option('comment_moderation_manual_all', '0') === '1';
        $autoApprovePrevious = $this->config->option('comment_moderation_auto_approve_previous', '1') !== '0';

        $trusted = $isTrustedModerator
            || (!$manualApprovalForAll && $autoApprovePrevious && $this->comments->hasPreviouslyApprovedComment($userId, $guestEmail));

        if (!$trusted) {
            return CommentStatus::Pending;
        }

        if (!$isTrustedModerator && $this->requiresHold($guestName, $guestEmail, $guestUrl, $content)) {
            return CommentStatus::Pending;
        }

        return CommentStatus::Approved;
    }

    private function requiresHold(string $guestName, string $guestEmail, ?string $guestUrl, string $content): bool
    {
        $linkLimit = (int) $this->config->option('comment_moderation_link_limit', '0');

        if ($linkLimit > 0 && $this->countLinks($content) > $linkLimit) {
            return true;
        }

        return $this->matchesKeywordList($this->moderationKeywords(), $guestName, $guestEmail, $guestUrl, $content);
    }

    private function countLinks(string $content): int
    {
        return preg_match_all('#https?://#i', $content);
    }

    /**
     * @param array<int, string> $keywords
     */
    private function matchesKeywordList(array $keywords, string $guestName, string $guestEmail, ?string $guestUrl, string $content): bool
    {
        if ($keywords === []) {
            return false;
        }

        $haystack = strtolower($guestName . ' ' . $guestEmail . ' ' . ($guestUrl ?? '') . ' ' . $content);

        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($haystack, strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function disallowedKeywords(): array
    {
        return $this->parseKeywordList((string) $this->config->option('comment_disallowed_keywords', ''));
    }

    /**
     * @return array<int, string>
     */
    private function moderationKeywords(): array
    {
        return $this->parseKeywordList((string) $this->config->option('comment_moderation_keywords', ''));
    }

    /**
     * @return array<int, string>
     */
    private function parseKeywordList(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
    }
}
