<?php

/**
 * Counts of what visitors search for, for Settings > Search.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.19.0
 */

declare(strict_types=1);

namespace LumoraPress\Services\Search;

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\PressConfig;
use PDOException;

/**
 * One row per distinct query with a running count — never who searched,
 * when exactly, or from where. Visitors sometimes type personal details
 * into a search box, so the full list is only ever shown to
 * administrators, publicPopular() only offers terms many searches share,
 * and rows are deleted once a query hasn't been searched for RETENTION_DAYS.
 */
final class SearchStatistics
{
    public const ENABLED_OPTION = 'search_statistics_enabled';

    public const RETENTION_DAYS = 180;

    private const MAX_QUERY_LENGTH = 200;

    /**
     * A term must have been searched at least this often, and have found
     * something last time, before it can be shown to visitors as a
     * popular search — one visitor's typed-in personal details never are.
     */
    public const PUBLIC_MIN_SEARCHES = 5;

    /** Pruning runs on roughly one recorded search in this many. */
    private const PRUNE_ONE_IN = 50;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly PressConfig $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->option(self::ENABLED_OPTION, '1') !== '0';
    }

    /**
     * Case and spacing are folded so "Xena" and " xena " count together.
     */
    public static function normalizeQuery(string $query): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($query)) ?? trim($query);

        return mb_substr(mb_strtolower($collapsed), 0, self::MAX_QUERY_LENGTH);
    }

    public function record(string $query, int $resultCount): void
    {
        $query = self::normalizeQuery($query);

        if ($query === '' || !$this->isEnabled()) {
            return;
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $resultCount = max(0, $resultCount);

        if ($this->increment($query, $resultCount, $now) === 0) {
            try {
                $this->database->execute(
                    'INSERT INTO ' . $this->table() . ' (query, searches, last_result_count, first_searched_at, last_searched_at)
                     VALUES (:query, 1, :result_count, :first_searched_at, :last_searched_at)',
                    ['query' => $query, 'result_count' => $resultCount, 'first_searched_at' => $now, 'last_searched_at' => $now],
                );
            } catch (PDOException) {
                // A simultaneous identical search inserted the row first.
                $this->increment($query, $resultCount, $now);
            }
        }

        if (random_int(1, self::PRUNE_ONE_IN) === 1) {
            $this->prune();
        }
    }

    /**
     * The most-searched queries still within the retention window.
     *
     * @return list<array{query: string, searches: int, lastResultCount: int, lastSearchedAt: DateTimeImmutable}>
     */
    public function popular(int $limit): array
    {
        return $this->rows('1 = 1', $limit);
    }

    /**
     * Popular queries whose most recent search found nothing — content
     * visitors want that the site doesn't have (or can't find).
     *
     * @return list<array{query: string, searches: int, lastResultCount: int, lastSearchedAt: DateTimeImmutable}>
     */
    public function withoutResults(int $limit): array
    {
        return $this->rows('last_result_count = 0', $limit);
    }

    /**
     * Popular searches safe to show visitors (see PUBLIC_MIN_SEARCHES).
     *
     * @return list<string>
     */
    public function publicPopular(int $limit): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        return array_column($this->rows(
            'searches >= ' . self::PUBLIC_MIN_SEARCHES . ' AND last_result_count > 0',
            $limit,
        ), 'query');
    }

    /**
     * @return array{queries: int, searches: int, since: ?DateTimeImmutable}
     */
    public function totals(): array
    {
        $row = $this->database->fetchOne(
            'SELECT COUNT(*) AS queries, COALESCE(SUM(searches), 0) AS searches, MIN(first_searched_at) AS since FROM ' . $this->table(),
        ) ?? [];

        return [
            'queries' => (int) ($row['queries'] ?? 0),
            'searches' => (int) ($row['searches'] ?? 0),
            'since' => ($row['since'] ?? null) !== null ? new DateTimeImmutable((string) $row['since']) : null,
        ];
    }

    public function clear(): void
    {
        $this->database->execute('DELETE FROM ' . $this->table());
    }

    public function prune(): int
    {
        return $this->database->execute(
            'DELETE FROM ' . $this->table() . ' WHERE last_searched_at < :cutoff',
            ['cutoff' => (new DateTimeImmutable('-' . self::RETENTION_DAYS . ' days'))->format('Y-m-d H:i:s')],
        );
    }

    private function increment(string $query, int $resultCount, string $now): int
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET searches = searches + 1, last_result_count = :result_count, last_searched_at = :last_searched_at
              WHERE query = :query',
            ['result_count' => $resultCount, 'last_searched_at' => $now, 'query' => $query],
        );
    }

    /**
     * @return list<array{query: string, searches: int, lastResultCount: int, lastSearchedAt: DateTimeImmutable}>
     */
    private function rows(string $where, int $limit): array
    {
        $limit = max(1, $limit);

        return array_map(
            static fn (array $row): array => [
                'query' => (string) $row['query'],
                'searches' => (int) $row['searches'],
                'lastResultCount' => (int) $row['last_result_count'],
                'lastSearchedAt' => new DateTimeImmutable((string) $row['last_searched_at']),
            ],
            $this->database->fetchAll(
                'SELECT query, searches, last_result_count, last_searched_at FROM ' . $this->table() . "
                  WHERE {$where}
                  ORDER BY searches DESC, last_searched_at DESC
                  LIMIT {$limit}",
            ),
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'search_queries';
    }
}
