<?php

/**
 * Site search across Posts and Pages (LP-014).
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
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\PressConfig;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\SearchResult;

/**
 * Site search across Posts, Pages, Categories, Tags, and Authors (LP-014).
 * Post/Page relevance ranking uses real MySQL/MariaDB FULLTEXT indexes
 * (`0011_add_fulltext_index_to_posts_and_pages.sql`) via
 * MATCH(...) AGAINST(...) — title matches are weighted higher than body
 * matches. Each table has two FULLTEXT indexes (title+content, and title
 * alone): MySQL requires a MATCH() column list to exactly match a defined
 * FULLTEXT index's column list, so scoring title matches separately from
 * the combined title+content match needs its own dedicated index — a
 * single MATCH(title, content) index cannot also serve MATCH(title)
 * queries. This is MySQL-only (SQLite has no FULLTEXT/MATCH AGAINST
 * syntax), same tradeoff as PressConfig::setOption()'s
 * "ON DUPLICATE KEY UPDATE" — see SqliteDatabaseFactory's docblock — so
 * searchTable() itself is covered only by Integration tests against a
 * real server. paginateResults() is deliberately factored out as a pure,
 * DB-free static method so the merge/sort/paginate math stays
 * unit-testable against plain SearchResult fixtures.
 *
 * Categories/Tags/Authors are a much smaller table (personal-blog scale —
 * tens, not tens of thousands, unlike Posts/Pages), so a plain `LIKE`
 * match is proportionate rather than needing its own FULLTEXT index; this
 * also means, unlike searchTable(), these three are portable to SQLite and
 * covered directly by Unit tests. Scoring uses a simple heuristic (exact
 * name match > name starts with > name contains > description contains)
 * rather than MySQL's relevance algorithm, so it can never be perfectly
 * comparable to a Post/Page FULLTEXT score — acceptable for a first pass,
 * see paginateResults()'s combined-ranking caveat below.
 *
 * Posts and Pages are queried independently (each capped at
 * search_max_results), merged, and re-sorted by score — a query matching
 * more rows than search_max_results in one table won't have every match
 * considered for the combined ranking. Acceptable for a personal-blog-
 * scale install (see CLAUDE.md's "tens of thousands of posts" performance
 * goal), not an unlimited-scale search engine.
 */
final class SearchService
{
    private const DEFAULT_PER_PAGE = 10;

    private const DEFAULT_MIN_LENGTH = 3;

    private const DEFAULT_MAX_RESULTS = 50;

    /** Heuristic relevance scores for the LIKE-based Category/Tag/Author match. */
    private const SCORE_EXACT_NAME = 10.0;

    private const SCORE_NAME_STARTS_WITH = 6.0;

    private const SCORE_NAME_CONTAINS = 3.0;

    private const SCORE_DESCRIPTION_CONTAINS = 1.5;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly PressConfig $config,
        private readonly ContentRenderer $content,
        private readonly UserService $users,
    ) {
    }

    /**
     * @return array{results: array<int, SearchResult>, total: int, page: int, perPage: int, totalPages: int, query: string}
     */
    public function search(string $query, int $page = 1): array
    {
        $query = trim($query);
        $page = max(1, $page);
        $minLength = max(1, (int) $this->config->option('search_min_length', (string) self::DEFAULT_MIN_LENGTH));

        if (mb_strlen($query) < $minLength) {
            return [
                'results' => [],
                'total' => 0,
                'page' => 1,
                'perPage' => self::DEFAULT_PER_PAGE,
                'totalPages' => 1,
                'query' => $query,
            ];
        }

        $maxResults = max(1, (int) $this->config->option('search_max_results', (string) self::DEFAULT_MAX_RESULTS));

        $candidates = [
            ...$this->searchTable('posts', 'post', $query, $maxResults),
            ...$this->searchTable('pages', 'page', $query, $maxResults),
            ...$this->searchCategories($query, $maxResults),
            ...$this->searchTags($query, $maxResults),
            ...$this->searchAuthors($query, $maxResults),
        ];

        $paginated = self::paginateResults($candidates, $page, self::DEFAULT_PER_PAGE, $maxResults);
        $paginated['query'] = $query;

        return $paginated;
    }

    /**
     * Merges pre-fetched candidates from both tables, sorts by relevance
     * score, caps at $maxResults combined, then slices out the requested
     * page — the only part of search() that has no MySQL-only dependency.
     *
     * @param array<int, SearchResult> $candidates
     * @return array{results: array<int, SearchResult>, total: int, page: int, perPage: int, totalPages: int}
     */
    public static function paginateResults(array $candidates, int $page, int $perPage, int $maxResults): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        usort($candidates, static fn (SearchResult $a, SearchResult $b): int => $b->score <=> $a->score);
        $candidates = array_slice($candidates, 0, max(1, $maxResults));

        $total = count($candidates);
        $offset = ($page - 1) * $perPage;

        return [
            'results' => array_slice($candidates, $offset, $perPage),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * @return array<int, SearchResult>
     */
    private function searchTable(string $tableSuffix, string $type, string $query, int $limit): array
    {
        $table = $this->tablePrefix . $tableSuffix;
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $where = "(status = 'published' OR (status = 'scheduled' AND published_at <= :now))";

        // Three distinct placeholders bound to the same value: MySQL's
        // native (non-emulated) prepare protocol rejects the same named
        // placeholder appearing more than once — see
        // PageService::listAllForParentSelect()'s docblock for the
        // original regression this mirrors.
        $rows = $this->database->fetchAll(
            "SELECT id, title, slug, content, content_format, excerpt, featured_image_id, published_at,
                    (MATCH(title, content) AGAINST(:query1 IN NATURAL LANGUAGE MODE)
                        + MATCH(title) AGAINST(:query2 IN NATURAL LANGUAGE MODE) * 2) AS relevance_score
             FROM {$table}
             WHERE {$where} AND MATCH(title, content) AGAINST(:query3 IN NATURAL LANGUAGE MODE)
             ORDER BY relevance_score DESC
             LIMIT {$limit}",
            ['now' => $now, 'query1' => $query, 'query2' => $query, 'query3' => $query],
        );

        return array_map(
            fn (array $row): SearchResult => new SearchResult(
                type: $type,
                id: (int) $row['id'],
                title: (string) $row['title'],
                slug: (string) $row['slug'],
                excerpt: ((string) ($row['excerpt'] ?? '')) !== ''
                    ? (string) $row['excerpt']
                    : make_excerpt($this->content->toPlainText(
                        (string) $row['content'],
                        ContentFormat::tryFrom((string) ($row['content_format'] ?? '')) ?? ContentFormat::Plain,
                    )),
                featuredImageId: $row['featured_image_id'] !== null ? (int) $row['featured_image_id'] : null,
                publishedAt: $row['published_at'] !== null ? new DateTimeImmutable((string) $row['published_at']) : null,
                score: (float) $row['relevance_score'],
            ),
            $rows,
        );
    }

    /**
     * Public (unlike searchTable()) so it's directly unit-testable against
     * SQLite — `LIKE` matching runs identically on both, unlike
     * searchTable()'s MySQL-only MATCH AGAINST, so there's no need to
     * route this through the MySQL-only search() entry point just to
     * exercise it.
     *
     * @return array<int, SearchResult>
     */
    public function searchCategories(string $query, int $limit): array
    {
        $rows = $this->database->fetchAll(
            'SELECT id, name, slug, description FROM ' . $this->tablePrefix . "categories
                WHERE name LIKE :name OR description LIKE :description
             ORDER BY id ASC
             LIMIT {$limit}",
            ['name' => '%' . $query . '%', 'description' => '%' . $query . '%'],
        );

        return array_map(
            fn (array $row): SearchResult => new SearchResult(
                type: 'category',
                id: (int) $row['id'],
                title: (string) $row['name'],
                slug: (string) $row['slug'],
                excerpt: make_excerpt((string) $row['description']),
                featuredImageId: null,
                publishedAt: null,
                score: self::nameMatchScore((string) $row['name'], (string) $row['description'], $query),
            ),
            $rows,
        );
    }

    /**
     * Public for the same testability reason as searchCategories() above.
     *
     * @return array<int, SearchResult>
     */
    public function searchTags(string $query, int $limit): array
    {
        $rows = $this->database->fetchAll(
            'SELECT id, name, slug, description FROM ' . $this->tablePrefix . "tags
                WHERE name LIKE :name OR description LIKE :description
             ORDER BY id ASC
             LIMIT {$limit}",
            ['name' => '%' . $query . '%', 'description' => '%' . $query . '%'],
        );

        return array_map(
            fn (array $row): SearchResult => new SearchResult(
                type: 'tag',
                id: (int) $row['id'],
                title: (string) $row['name'],
                slug: (string) $row['slug'],
                excerpt: make_excerpt((string) $row['description']),
                featuredImageId: null,
                publishedAt: null,
                score: self::nameMatchScore((string) $row['name'], (string) $row['description'], $query),
            ),
            $rows,
        );
    }

    /**
     * Restricted to non-trashed users who have authored at least one
     * published post — an author with nothing publicly attributed to them
     * has no useful destination for a search result to link to (their
     * `/author/{slug}` archive would simply be empty), and this keeps
     * search from surfacing the existence of e.g. Subscriber-only accounts
     * that have never published anything. Public for the same testability
     * reason as searchCategories() above.
     *
     * @return array<int, SearchResult>
     */
    public function searchAuthors(string $query, int $limit): array
    {
        $rows = $this->database->fetchAll(
            'SELECT id, display_name FROM ' . $this->tablePrefix . 'users
                WHERE trashed_at IS NULL AND display_name LIKE :name
                  AND EXISTS (
                      SELECT 1 FROM ' . $this->tablePrefix . 'posts
                       WHERE author_id = ' . $this->tablePrefix . "users.id AND status = 'published'
                  )
             ORDER BY id ASC
             LIMIT {$limit}",
            ['name' => '%' . $query . '%'],
        );

        $results = [];

        foreach ($rows as $row) {
            $author = $this->users->findById((int) $row['id']);

            if ($author === null) {
                continue;
            }

            $results[] = new SearchResult(
                type: 'author',
                id: $author->id,
                title: $author->displayName,
                slug: $this->users->authorSlug($author),
                excerpt: '',
                featuredImageId: null,
                publishedAt: null,
                score: self::nameMatchScore($author->displayName, '', $query),
            );
        }

        return $results;
    }

    /**
     * The LIKE-based heuristic relevance score shared by
     * searchCategories()/searchTags()/searchAuthors() — see this class's
     * own docblock for why this can never be perfectly comparable to a
     * Post/Page FULLTEXT score.
     */
    private static function nameMatchScore(string $name, string $description, string $query): float
    {
        $lowerName = mb_strtolower($name);
        $lowerQuery = mb_strtolower($query);

        if ($lowerName === $lowerQuery) {
            return self::SCORE_EXACT_NAME;
        }

        if (str_starts_with($lowerName, $lowerQuery)) {
            return self::SCORE_NAME_STARTS_WITH;
        }

        if (str_contains($lowerName, $lowerQuery)) {
            return self::SCORE_NAME_CONTAINS;
        }

        return self::SCORE_DESCRIPTION_CONTAINS;
    }
}
