<?php

/**
 * Bridges comment reactions, reporting, and per-visitor state to the comment_list() template tag.
 *
 * @package LumoraPress
 * @subpackage Theme
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.18.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

use Closure;
use LumoraPress\Services\CommentReactionService;
use LumoraPress\Services\CommentReportService;

/**
 * Same bridge shape as Authors/FeaturedImages: comment_list() is a
 * procedural template tag with no route to services of its own. Also
 * carries the per-request state it batches up front (reaction counts and
 * the visitor's own choices for the whole thread in two queries), and the
 * "comments were rendered" flag FooterAssets uses to load comments.js.
 */
final class CommentExtras
{
    private static ?CommentReactionService $reactions = null;

    private static ?CommentReportService $reports = null;

    /** @var (Closure(): ?string)|null */
    private static ?Closure $voterKeyResolver = null;

    /** @var array<int, array<string, int>> */
    private static array $counts = [];

    /** @var array<int, string> */
    private static array $choices = [];

    private static bool $used = false;

    /**
     * @param Closure(): ?string $voterKeyResolver
     */
    public static function set(CommentReactionService $reactions, CommentReportService $reports, Closure $voterKeyResolver): void
    {
        self::$reactions = $reactions;
        self::$reports = $reports;
        self::$voterKeyResolver = $voterKeyResolver;
    }

    public static function reactions(): ?CommentReactionService
    {
        return self::$reactions;
    }

    public static function reports(): ?CommentReportService
    {
        return self::$reports;
    }

    public static function voterKey(): ?string
    {
        return self::$voterKeyResolver !== null ? (self::$voterKeyResolver)() : null;
    }

    /**
     * Loads reaction counts and this visitor's choices for every comment
     * about to render, so each comment's buttons need no query of their own.
     *
     * @param array<int, int> $commentIds
     */
    public static function prime(array $commentIds): void
    {
        if (self::$reactions === null || !self::$reactions->isEnabled() || $commentIds === []) {
            return;
        }

        self::$counts = self::$reactions->countsFor($commentIds) + self::$counts;
        $voterKey = self::voterKey();

        if ($voterKey !== null) {
            self::$choices = self::$reactions->choicesFor($commentIds, $voterKey) + self::$choices;
        }
    }

    /**
     * @return array<string, int>
     */
    public static function countsFor(int $commentId): array
    {
        return self::$counts[$commentId] ?? [];
    }

    public static function choiceFor(int $commentId): ?string
    {
        return self::$choices[$commentId] ?? null;
    }

    public static function markUsed(): void
    {
        self::$used = true;
    }

    public static function isUsed(): bool
    {
        return self::$used;
    }

    public static function reset(): void
    {
        self::$reactions = null;
        self::$reports = null;
        self::$voterKeyResolver = null;
        self::$counts = [];
        self::$choices = [];
        self::$used = false;
    }
}
