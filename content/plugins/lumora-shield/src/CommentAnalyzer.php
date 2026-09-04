<?php

/**
 * Content and behavioral spam heuristics for the bundled Lumora Shield plugin's Comment Analysis module.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LumoraShield;

use LumoraPress\Core\ActiveKernel;

/**
 * Pushes a comment toward Spam via the existing 'comment_is_spam' filter
 * (the same one Akismet already uses — see SiteController::submitComment()'s
 * own docblock, which names Lumora Shield by name as the intended
 * eventual consumer). No blacklist/reputation-score engine exists yet, so
 * this is deliberately a set of independent, individually-explainable
 * checks — any one of them tripping is enough to flag Spam, mirroring
 * how a single Akismet "yes" already works today. Nothing here can
 * *un-spam* a comment another listener already flagged (see the
 * `comment_is_spam` filter's own contract).
 */
final class CommentAnalyzer
{
    /** More than this many http(s):// links in one comment is suspicious. */
    private const MAX_LINKS = 3;

    /** Comments this short are exempt from the uppercase/punctuation ratio checks below — not enough signal in "WOW!!!" to matter. */
    private const MIN_LENGTH_FOR_RATIO_CHECKS = 20;

    private const MAX_UPPERCASE_RATIO = 0.7;

    /** 3+ consecutive punctuation marks, e.g. "buy now!!!" or "really???". */
    private const REPEATED_PUNCTUATION_PATTERN = '/[!?]{4,}/';

    /** A run of 10+ characters (spaces included) repeated 3+ times back to back. */
    private const REPEATED_PHRASE_PATTERN = '/(.{10,}?)\1{2,}/us';

    /** Zero-width and bidi-override characters — invisible-to-the-eye content used to smuggle text past keyword filters or disguise link destinations. */
    private const HIDDEN_CHARACTER_PATTERN = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    private const MIN_CONTENT_LENGTH = 3;

    private const MAX_CONTENT_LENGTH = 10000;

    /** Posting more than this many comments (any status) from one IP within RATE_WINDOW_SECONDS is flagged. */
    private const MAX_COMMENTS_PER_WINDOW = 5;

    private const RATE_WINDOW_SECONDS = 3600;

    /**
     * @return array{isSpam: bool, reasons: array<int, string>}
     */
    public function analyze(string $content, ?int $userId, ?string $guestEmail, string $ipAddress): array
    {
        $reasons = [];

        foreach ($this->contentReasons($content) as $reason) {
            $reasons[] = $reason;
        }

        foreach ($this->behavioralReasons($content, $userId, $guestEmail, $ipAddress) as $reason) {
            $reasons[] = $reason;
        }

        return ['isSpam' => $reasons !== [], 'reasons' => $reasons];
    }

    /**
     * Pure content-string checks, no database access — split out as its
     * own public method (rather than folded only into analyze()) so it's
     * unit-testable without needing ActiveKernel/a real Kernel, unlike
     * behavioralReasons() below.
     *
     * @return array<int, string>
     */
    public function contentReasons(string $content): array
    {
        $reasons = [];
        $trimmed = trim($content);
        $length = mb_strlen($trimmed);

        if ($length < self::MIN_CONTENT_LENGTH) {
            $reasons[] = 'extremely_short';
        }

        if ($length > self::MAX_CONTENT_LENGTH) {
            $reasons[] = 'extremely_long';
        }

        if ($this->countLinks($content) > self::MAX_LINKS) {
            $reasons[] = 'excessive_links';
        }

        if ($length >= self::MIN_LENGTH_FOR_RATIO_CHECKS && $this->uppercaseRatio($content) > self::MAX_UPPERCASE_RATIO) {
            $reasons[] = 'excessive_uppercase';
        }

        if (preg_match(self::REPEATED_PUNCTUATION_PATTERN, $content) === 1) {
            $reasons[] = 'excessive_punctuation';
        }

        if (preg_match(self::REPEATED_PHRASE_PATTERN, $content) === 1) {
            $reasons[] = 'repeated_phrase';
        }

        if (preg_match(self::HIDDEN_CHARACTER_PATTERN, $content) === 1) {
            $reasons[] = 'hidden_characters';
        }

        return $reasons;
    }

    /**
     * @return array<int, string>
     */
    private function behavioralReasons(string $content, ?int $userId, ?string $guestEmail, string $ipAddress): array
    {
        $reasons = [];
        $comments = ActiveKernel::instance()->comments;

        if ($comments->hasPreviousSpamHistory($userId, $guestEmail)) {
            $reasons[] = 'previous_spam_history';
        }

        if ($comments->countRecentFromIp($ipAddress, self::RATE_WINDOW_SECONDS) >= self::MAX_COMMENTS_PER_WINDOW) {
            $reasons[] = 'posting_frequency';
        }

        if (trim($content) !== '' && $comments->identicalContentExists($content)) {
            $reasons[] = 'duplicate_comment';
        }

        return $reasons;
    }

    private function countLinks(string $content): int
    {
        return preg_match_all('#https?://#i', $content);
    }

    private function uppercaseRatio(string $content): float
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $content) ?? '';

        if ($letters === '') {
            return 0.0;
        }

        $uppercase = preg_replace('/[^\p{Lu}]/u', '', $letters) ?? '';

        return mb_strlen($uppercase) / mb_strlen($letters);
    }
}
