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

    /**
     * Rolling windows ending now, like Visitor Stats' totals: "today" is since midnight, the
     * week and month are the last 7 and 30 days. Only as deep as the log retention period.
     *
     * @return array{today: int, this_week: int, this_month: int}
     */
    public function totals(): array
    {
        return [
            'today' => $this->countSince(date('Y-m-d 00:00:00')),
            'this_week' => $this->countSince(date('Y-m-d H:i:s', time() - (7 * 86400))),
            'this_month' => $this->countSince(date('Y-m-d H:i:s', time() - (30 * 86400))),
        ];
    }

    /**
     * @return array<int, array{ipAddress: string, count: int}>
     */
    public function topIps(int $days, int $limit = 5): array
    {
        $rows = $this->database->fetchAll(
            'SELECT ip_address, COUNT(*) AS flagged FROM ' . $this->table()
                . ' WHERE created_at >= :since GROUP BY ip_address ORDER BY flagged DESC, ip_address ASC LIMIT ' . max(1, $limit),
            ['since' => date('Y-m-d H:i:s', time() - ($days * 86400))],
        );

        return array_map(static fn (array $row): array => [
            'ipAddress' => (string) $row['ip_address'],
            'count' => (int) $row['flagged'],
        ], $rows);
    }

    /**
     * Which checks fired most over the last $days days. Counted in PHP because the rules are
     * stored as one comma-separated column; the window is bounded by the log retention period,
     * and the row cap keeps a flood from turning this into a large read.
     *
     * @return array<int, array{rule: string, count: int}>
     */
    public function topRules(int $days, int $limit = 5): array
    {
        $rows = $this->database->fetchAll(
            'SELECT triggered_rules FROM ' . $this->table() . ' WHERE created_at >= :since ORDER BY created_at DESC LIMIT 5000',
            ['since' => date('Y-m-d H:i:s', time() - ($days * 86400))],
        );

        $counts = [];

        foreach ($rows as $row) {
            foreach (explode(',', (string) $row['triggered_rules']) as $rule) {
                if ($rule !== '') {
                    $counts[$rule] = ($counts[$rule] ?? 0) + 1;
                }
            }
        }

        arsort($counts);

        $top = [];

        foreach (array_slice($counts, 0, max(1, $limit), true) as $rule => $count) {
            $top[] = ['rule' => (string) $rule, 'count' => $count];
        }

        return $top;
    }

    public function deleteOlderThan(int $retentionDays): void
    {
        $this->database->execute(
            'DELETE FROM ' . $this->table() . ' WHERE created_at < :cutoff',
            ['cutoff' => date('Y-m-d H:i:s', time() - ($retentionDays * 86400))],
        );
    }

    private function countSince(string $since): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE created_at >= :since',
            ['since' => $since],
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'spam_events';
    }
}
