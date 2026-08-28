<?php

/**
 * Per-post daily view counting and totals for the Visitor & Post View Statistics plugin (LPP-014).
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

namespace LumoraPress\Plugins\VisitorStats;

use LumoraPress\Core\Database\Database;

/**
 * One row per (post_id, calendar day) — daily aggregation, not a
 * per-request log, the same "store the rollup, not the event" shape
 * MediaStatsService uses for downloads. Takes Database directly (unlike
 * ViewStatsService below, which needs ActiveKernel for the config-backed
 * table prefix too) so it stays trivially unit-testable against the PHP
 * Test Suite's SQLite-backed fixtures.
 */
final class PostViewService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    /**
     * A portable check-then-insert/update rather than a MySQL-only
     * `ON DUPLICATE KEY UPDATE` — mirrors MediaStatsService::
     * recordDownload()'s identical reasoning, keeping this exercisable
     * against SQLite unit tests, not just MySQL/MariaDB integration
     * tests.
     */
    public function recordView(int $postId): void
    {
        $today = date('Y-m-d');
        $exists = ((int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE post_id = :post_id AND view_date = :view_date',
            ['post_id' => $postId, 'view_date' => $today],
        )) > 0;

        if ($exists) {
            $this->database->execute(
                'UPDATE ' . $this->table() . ' SET views = views + 1 WHERE post_id = :post_id AND view_date = :view_date',
                ['post_id' => $postId, 'view_date' => $today],
            );
        } else {
            $this->database->execute(
                'INSERT INTO ' . $this->table() . ' (post_id, view_date, views) VALUES (:post_id, :view_date, 1)',
                ['post_id' => $postId, 'view_date' => $today],
            );
        }
    }

    public function totalViews(int $postId): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COALESCE(SUM(views), 0) FROM ' . $this->table() . ' WHERE post_id = :post_id',
            ['post_id' => $postId],
        );
    }

    public function viewsForLastDays(int $postId, int $days): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COALESCE(SUM(views), 0) FROM ' . $this->table() . ' WHERE post_id = :post_id AND view_date >= :since',
            ['post_id' => $postId, 'since' => $this->daysAgo($days)],
        );
    }

    /**
     * @return array{today: int, this_week: int, this_month: int, all_time: int}
     */
    public function siteWideTotals(): array
    {
        return [
            'today' => (int) $this->database->fetchColumn(
                'SELECT COALESCE(SUM(views), 0) FROM ' . $this->table() . ' WHERE view_date = :today',
                ['today' => date('Y-m-d')],
            ),
            'this_week' => (int) $this->database->fetchColumn(
                'SELECT COALESCE(SUM(views), 0) FROM ' . $this->table() . ' WHERE view_date >= :since',
                ['since' => $this->daysAgo(7)],
            ),
            'this_month' => (int) $this->database->fetchColumn(
                'SELECT COALESCE(SUM(views), 0) FROM ' . $this->table() . ' WHERE view_date >= :since',
                ['since' => $this->daysAgo(30)],
            ),
            'all_time' => (int) $this->database->fetchColumn(
                'SELECT COALESCE(SUM(views), 0) FROM ' . $this->table(),
            ),
        ];
    }

    /**
     * Most-viewed posts over the last $days days — a bounded, capped
     * list, same "quick, capped listing" precedent as
     * MediaStatsService::mostDownloaded().
     *
     * @return array<int, array{post_id: int, views: int}>
     */
    public function mostViewed(int $days, int $limit = 5): array
    {
        $limit = max(1, $limit);

        $rows = $this->database->fetchAll(
            'SELECT post_id, SUM(views) AS views FROM ' . $this->table() . '
              WHERE view_date >= :since
              GROUP BY post_id
              ORDER BY views DESC
              LIMIT ' . $limit,
            ['since' => $this->daysAgo($days)],
        );

        return array_map(static fn (array $row): array => [
            'post_id' => (int) $row['post_id'],
            'views' => (int) $row['views'],
        ], $rows);
    }

    private function daysAgo(int $days): string
    {
        return date('Y-m-d', strtotime('-' . max(0, $days) . ' days'));
    }

    private function table(): string
    {
        return $this->tablePrefix . 'post_views';
    }
}
