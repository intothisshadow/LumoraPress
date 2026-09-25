<?php

/**
 * Visitor reports flagging a comment for moderators, with an optional auto-hold threshold.
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

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\PressConfig;
use PDOException;

/**
 * Reporters are keyed the same way CommentReactionService keys voters
 * ("u:{id}" or "g:{hash}"), so one person can report a comment once.
 * Reports are advisory: they never delete anything, and at most move an
 * approved comment back to Pending once the configured threshold is hit.
 */
final class CommentReportService
{
    /** Reason key => label shown to reporters and moderators. */
    public const REASONS = [
        'spam' => 'Spam',
        'abusive' => 'Abusive or harassing',
        'off_topic' => 'Off-topic',
        'other' => 'Something else',
    ];

    private const DEFAULT_THRESHOLD = 3;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly PressConfig $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->option('comment_reporting_enabled', '0') === '1';
    }

    /**
     * Reports needed to hold an approved comment for moderation; 0 never holds automatically.
     */
    public function threshold(): int
    {
        return max(0, (int) $this->config->option('comment_report_threshold', (string) self::DEFAULT_THRESHOLD));
    }

    /**
     * Returns false when this reporter already reported the comment (or
     * the reason is unknown), true when a new report was recorded.
     */
    public function report(int $commentId, string $reason, string $reporterKey, ?int $userId): bool
    {
        if (!array_key_exists($reason, self::REASONS)) {
            return false;
        }

        try {
            $this->database->execute(
                'INSERT INTO ' . $this->table() . ' (comment_id, reason, reporter_key, user_id, created_at) VALUES (:comment_id, :reason, :reporter_key, :user_id, :created_at)',
                [
                    'comment_id' => $commentId,
                    'reason' => $reason,
                    'reporter_key' => $reporterKey,
                    'user_id' => $userId,
                    'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (PDOException) {
            // The unique (comment_id, reporter_key) index: already reported.
            return false;
        }

        return true;
    }

    public function hasReported(int $commentId, string $reporterKey): bool
    {
        return $this->database->fetchColumn(
            'SELECT 1 FROM ' . $this->table() . ' WHERE comment_id = :comment_id AND reporter_key = :reporter_key',
            ['comment_id' => $commentId, 'reporter_key' => $reporterKey],
        ) !== null;
    }

    public function countFor(int $commentId): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE comment_id = :comment_id',
            ['comment_id' => $commentId],
        );
    }

    /**
     * Report counts per reason for each comment, for the admin Comments screen.
     *
     * @param array<int, int> $commentIds
     * @return array<int, array<string, int>> commentId => [reason => count]
     */
    public function summariesFor(array $commentIds): array
    {
        $params = [];

        foreach (array_values(array_unique(array_map('intval', $commentIds))) as $index => $id) {
            $params['id_' . $index] = $id;
        }

        if ($params === []) {
            return [];
        }

        $placeholders = implode(', ', array_map(static fn (string $key): string => ':' . $key, array_keys($params)));
        $summaries = [];

        foreach ($this->database->fetchAll(
            'SELECT comment_id, reason, COUNT(*) AS total FROM ' . $this->table() . " WHERE comment_id IN ({$placeholders}) GROUP BY comment_id, reason",
            $params,
        ) as $row) {
            $summaries[(int) $row['comment_id']][(string) $row['reason']] = (int) $row['total'];
        }

        return $summaries;
    }

    public function reportedCommentCount(): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(DISTINCT comment_id) FROM ' . $this->table());
    }

    /**
     * A moderator reviewed the comment and kept it: clears its reports so
     * it drops off the Reported list (and its count starts from zero).
     */
    public function dismiss(int $commentId): int
    {
        return $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE comment_id = :comment_id', ['comment_id' => $commentId]);
    }

    private function table(): string
    {
        return $this->tablePrefix . 'comment_reports';
    }
}
