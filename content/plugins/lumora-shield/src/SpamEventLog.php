<?php

/**
 * Per-submission spam record for the bundled Lumora Shield plugin.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.22.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LumoraShield;

use LumoraPress\Core\Database\Database;

/**
 * Stores why a comment or contact-form submission was flagged, so a moderator
 * who finds a legitimate message in Spam can see which rule caught it. Takes
 * Database directly (rather than reaching for ActiveKernel) so it stays
 * unit-testable against the test suite's SQLite fixtures.
 */
final class SpamEventLog
{
    private const MAX_TEXT_LENGTH = 255;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    /**
     * @param array<int, string> $rules
     */
    public function record(
        string $source,
        array $rules,
        string $action,
        string $ipAddress,
        string $authorName = '',
        string $authorEmail = '',
        ?int $commentId = null,
        ?int $userId = null,
    ): void {
        $this->database->execute(
            'INSERT INTO ' . $this->table() . ' (source, comment_id, user_id, author_name, author_email, ip_address, spam_score, triggered_rules, action_taken, created_at)'
                . ' VALUES (:source, :comment_id, :user_id, :author_name, :author_email, :ip_address, :spam_score, :triggered_rules, :action_taken, :created_at)',
            [
                'source' => $source,
                'comment_id' => $commentId,
                'user_id' => $userId,
                'author_name' => mb_substr($authorName, 0, self::MAX_TEXT_LENGTH),
                'author_email' => mb_substr($authorEmail, 0, self::MAX_TEXT_LENGTH),
                'ip_address' => $ipAddress,
                // Each independent check carries equal weight, so the score is simply how many fired.
                'spam_score' => count($rules),
                'triggered_rules' => mb_substr(implode(',', $rules), 0, self::MAX_TEXT_LENGTH),
                'action_taken' => $action,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * @return array<int, array{id: int, source: string, commentId: ?int, userId: ?int, authorName: string, authorEmail: string, ipAddress: string, spamScore: int, rules: array<int, string>, action: string, createdAt: string}>
     */
    public function recent(int $limit = 25): array
    {
        $rows = $this->database->fetchAll(
            'SELECT id, source, comment_id, user_id, author_name, author_email, ip_address, spam_score, triggered_rules, action_taken, created_at FROM '
                . $this->table() . ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit),
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'source' => (string) $row['source'],
            'commentId' => $row['comment_id'] === null ? null : (int) $row['comment_id'],
            'userId' => $row['user_id'] === null ? null : (int) $row['user_id'],
            'authorName' => (string) $row['author_name'],
            'authorEmail' => (string) $row['author_email'],
            'ipAddress' => (string) $row['ip_address'],
            'spamScore' => (int) $row['spam_score'],
            'rules' => array_values(array_filter(explode(',', (string) $row['triggered_rules']), static fn (string $rule): bool => $rule !== '')),
            'action' => (string) $row['action_taken'],
            'createdAt' => (string) $row['created_at'],
        ], $rows);
    }

    public function deleteOlderThan(int $retentionDays): void
    {
        $this->database->execute(
            'DELETE FROM ' . $this->table() . ' WHERE created_at < :cutoff',
            ['cutoff' => date('Y-m-d H:i:s', time() - ($retentionDays * 86400))],
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'spam_events';
    }
}
