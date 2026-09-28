<?php

/**
 * Site search across Posts, Pages, Categories, Tags, Authors, and plugin-registered content.
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
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\PressConfig;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\SearchCriteria;
use LumoraPress\Models\SearchResult;
use LumoraPress\Services\Search\PublicContent;
use LumoraPress\Services\Search\SearchQuery;
use LumoraPress\Services\Search\SearchStatistics;
use LumoraPress\Services\Search\SearchVocabulary;

/**
 * Posts and Pages are matched with real MySQL/MariaDB FULLTEXT indexes:
 * BOOLEAN MODE decides what matches (phrases, prefixes, exclusions), and
 * NATURAL LANGUAGE MODE scores contribute most of the ranking, with title
 * matches weighted double. MySQL requires a MATCH() column list to equal a
 * defined index exactly, hence the two indexes per table (title+content,
 * title). That SQL is MySQL-only and covered by Integration tests.
 *
 * Categories/Tags/Authors are personal-blog scale, so a portable `LIKE`
 * match with a simple name heuristic is proportionate; its scores are
 * never perfectly comparable to FULLTEXT relevance.
 *
 * Every type is a "provider" callable, filterable through
 * `search_providers`, each capped at search_max_results before the merge —
 * a type matching more rows than the cap won't have every match considered.
 */
final class SearchService
{
    private const DEFAULT_PER_PAGE = 10;

    private const DEFAULT_MIN_LENGTH = 3;

    private const DEFAULT_MAX_RESULTS = 50;

    private const TITLE_WEIGHT = 2;

    private const SUGGESTION_COUNT = 5;

    public const PARTIAL_MATCHING_OPTION = 'search_partial_matching';

    public const FUZZY_MATCHING_OPTION = 'search_fuzzy_matching';

    public const SUGGESTIONS_OPTION = 'search_suggestions_enabled';

    public const LIVE_OPTION = 'search_live_enabled';

    public const HISTORY_OPTION = 'search_history_enabled';

    public const POPULAR_OPTION = 'search_popular_enabled';

    /** How many recent searches a signed-in user's account keeps. */
    public const RECENT_SEARCH_LIMIT = 8;

    private const LIVE_RESULT_COUNT = 6;

    private const POPULAR_SEARCH_COUNT = 6;

    /** Bounds how much a long query can be widened by fuzzy/partial matching. */
    private const MAX_EXPANDED_TERMS = 8;

    private const PARTIAL_ALTERNATIVES_PER_TERM = 8;

    private const FUZZY_ALTERNATIVES_PER_TERM = 4;

    /** "Did you mean?" is only worked out when a search finds fewer than this. */
    public const DID_YOU_MEAN_BELOW = 5;

    private const TITLE_SUGGESTION_COUNT = 8;

    /** @var array<string, string> Core content types and their display labels. */
    public const TYPE_LABELS = [
        'post' => 'Post',
        'page' => 'Page',
        'category' => 'Category',
        'tag' => 'Tag',
        'author' => 'Author',
    ];

    /** Heuristic relevance scores for the LIKE-based Category/Tag/Author match. */
    private const SCORE_EXACT_NAME = 10.0;

    private const SCORE_NAME_STARTS_WITH = 6.0;

    private const SCORE_NAME_CONTAINS = 3.0;

    private const SCORE_DESCRIPTION_CONTAINS = 1.5;

    /** One-entry memo so the post and page providers share one resolveFilters() per search. */
    private ?SearchCriteria $memoCriteria = null;

    /** @var array{categoryId: ?int, tagId: ?int, authorId: ?int}|null */
    private ?array $memoFilters = null;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly PressConfig $config,
        private readonly ContentRenderer $content,
        private readonly UserService $users,
        private readonly ?HookManager $hooks = null,
        private readonly ?SearchVocabulary $vocabulary = null,
        private readonly ?SearchStatistics $statistics = null,
    ) {
    }

    public function vocabulary(): ?SearchVocabulary
    {
        return $this->vocabulary;
    }

    public function statistics(): ?SearchStatistics
    {
        return $this->statistics;
    }

    public function minimumQueryLength(): int
    {
        return max(1, (int) $this->config->option('search_min_length', (string) self::DEFAULT_MIN_LENGTH));
    }

    public function suggestionsEnabled(): bool
    {
        return $this->config->option(self::SUGGESTIONS_OPTION, '1') !== '0';
    }

    public function liveSearchEnabled(): bool
    {
        return $this->config->option(self::LIVE_OPTION, '0') === '1';
    }

    public function historyEnabled(): bool
    {
        return $this->config->option(self::HISTORY_OPTION, '0') === '1';
    }

    /**
     * Popular searches can only be shown while statistics are being kept.
     */
    public function popularSearchesEnabled(): bool
    {
        return $this->config->option(self::POPULAR_OPTION, '0') === '1' && $this->statistics?->isEnabled() === true;
    }

    /**
     * @return list<string>
     */
    public function popularSearches(): array
    {
        return $this->popularSearchesEnabled() ? ($this->statistics?->publicPopular(self::POPULAR_SEARCH_COUNT) ?? []) : [];
    }

    /**
     * The first few best matches for the search box's live results, plus
     * how many there are in total.
     *
     * @return array{results: list<SearchResult>, total: int}
     */
    public function liveResults(string $text): array
    {
        $found = $this->search(new SearchCriteria(mb_substr(trim($text), 0, 200)));

        return ['results' => array_slice(array_values($found['results']), 0, self::LIVE_RESULT_COUNT), 'total' => $found['total']];
    }

    /**
     * A plain string is treated as an unfiltered, relevance-sorted query.
     *
     * @return array{results: array<int, SearchResult>, total: int, page: int, perPage: int, totalPages: int, query: string, criteria: SearchCriteria}
     */
    public function search(SearchCriteria|string $criteria, int $page = 1): array
    {
        if (is_string($criteria)) {
            $criteria = new SearchCriteria(trim($criteria), max(1, $page));
        }

        $filtered = $this->applyFilters('search_criteria', $criteria);
        $criteria = $filtered instanceof SearchCriteria ? $filtered : $criteria;

        $query = trim($criteria->query);
        $parsed = SearchQuery::parse($query);
        $minLength = $this->minimumQueryLength();
        $filters = mb_strlen($query) >= $minLength && $parsed->hasPositiveTerms() ? $this->filtersFor($criteria) : null;

        if ($filters === null) {
            return [
                'results' => [],
                'total' => 0,
                'page' => 1,
                'perPage' => self::DEFAULT_PER_PAGE,
                'totalPages' => 1,
                'query' => $query,
                'criteria' => $criteria,
            ];
        }

        $parsed = $this->expandQuery($parsed);
        $maxResults = max(1, (int) $this->config->option('search_max_results', (string) self::DEFAULT_MAX_RESULTS));
        $candidates = [];

        foreach ($this->providersFor($criteria) as $provider) {
            foreach ($provider($criteria, $parsed, $maxResults) as $result) {
                if ($result instanceof SearchResult) {
                    $candidates[] = $result;
                }
            }
        }

        if ($this->hooks?->hasFilter('search_result_score') === true) {
            $candidates = array_map(
                fn (SearchResult $result): SearchResult => $result->withScore(
                    (float) $this->applyFilters('search_result_score', $result->score, $result, $criteria),
                ),
                $candidates,
            );
        }

        $filteredCandidates = $this->applyFilters('search_results', $candidates, $criteria);
        $candidates = is_array($filteredCandidates)
            ? array_values(array_filter($filteredCandidates, static fn (mixed $result): bool => $result instanceof SearchResult))
            : $candidates;

        $paginated = self::paginateResults($candidates, $criteria->page, self::DEFAULT_PER_PAGE, $maxResults, $criteria->sort);
        $paginated['query'] = $query;
        $paginated['criteria'] = $criteria;

        return $paginated;
    }

    /**
     * A corrected version of the search text when some of its words aren't
     * on the site's word list and swapping them for the closest ones that
     * are finds more results, else null. The correction is searched for
     * real before being offered, so it can never point at nothing, or at
     * a word that only appears in content visitors can't see.
     */
    public function didYouMean(SearchCriteria $criteria, int $currentTotal): ?string
    {
        if ($this->vocabulary === null) {
            return null;
        }

        $parsed = SearchQuery::parse($criteria->query);

        if (!$parsed->hasPositiveTerms()) {
            return null;
        }

        $this->vocabulary->ensureBuilt();
        $replacements = [];

        foreach ($parsed->positiveWords() as $word) {
            if (SearchVocabulary::uniqueWords($word) === [] || $this->vocabulary->contains($word)) {
                continue;
            }

            $closest = $this->vocabulary->closestWord($word);

            if ($closest !== null) {
                $replacements[$word] = $closest;
            }
        }

        if ($replacements === []) {
            return null;
        }

        $corrected = $parsed->toQueryString($replacements);

        return $this->search($criteria->withQuery($corrected))['total'] > $currentTotal ? $corrected : null;
    }

    /**
     * Titles of public posts and pages containing every word typed so far
     * (the last one possibly half-typed), best match first — for the
     * search box's suggestion list. MySQL-only, like searchPosts().
     *
     * @return list<string>
     */
    public function suggestTitles(string $text): array
    {
        $parsed = SearchQuery::parse($text);
        $minLength = $this->minimumQueryLength();

        if (!$parsed->hasPositiveTerms() || mb_strlen(trim($text)) < $minLength) {
            return [];
        }

        $expression = implode(' ', array_map(static fn (string $word): string => '+' . $word . '*', $parsed->positiveWords()));
        $types = $this->searchableTypeLabels();
        $limit = self::TITLE_SUGGESTION_COUNT;
        $rows = [];

        foreach (['post' => 'posts', 'page' => 'pages'] as $type => $tableSuffix) {
            if (!isset($types[$type])) {
                continue;
            }

            $params = $type === 'post' ? $this->nowParams() : ['now' => $this->now()];
            $where = $type === 'post'
                ? PublicContent::postWhere() . $this->categoryExclusionClause($this->excludedCategoryIds(), $params)
                : PublicContent::pageWhere() . $this->idListClause('p.id', 'NOT IN', $this->excludedPageIds(), 'excluded_page', $params);

            array_push($rows, ...$this->database->fetchAll(
                'SELECT p.title, MATCH(p.title) AGAINST(:expression1 IN BOOLEAN MODE) AS score FROM ' . $this->tablePrefix . $tableSuffix . " p
                  WHERE {$where} AND MATCH(p.title) AGAINST(:expression2 IN BOOLEAN MODE)
                  ORDER BY score DESC, p.published_at DESC
                  LIMIT {$limit}",
                $params + ['expression1' => $expression, 'expression2' => $expression],
            ));
        }

        usort($rows, static fn (array $a, array $b): int => (float) $b['score'] <=> (float) $a['score']);
        $titles = [];

        foreach ($rows as $row) {
            $title = trim((string) $row['title']);
            $titles[mb_strtolower($title)] ??= $title;
        }

        return array_slice(array_values($titles), 0, $limit);
    }

    /**
     * Keeps the $maxResults most relevant candidates, orders them by $sort,
     * then slices out the requested page. Undated results (categories,
     * tags, authors) sort after dated ones for the date orders.
     *
     * @param array<int, SearchResult> $candidates
     * @return array{results: array<int, SearchResult>, total: int, page: int, perPage: int, totalPages: int}
     */
    public static function paginateResults(
        array $candidates,
        int $page,
        int $perPage,
        int $maxResults,
        string $sort = SearchCriteria::SORT_RELEVANCE,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        usort($candidates, static fn (SearchResult $a, SearchResult $b): int => $b->score <=> $a->score);
        $candidates = array_slice($candidates, 0, max(1, $maxResults));

        if ($sort !== SearchCriteria::SORT_RELEVANCE) {
            usort($candidates, static fn (SearchResult $a, SearchResult $b): int => self::compareForSort($a, $b, $sort));
        }

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
     * Every registered provider keyed by content type, core types first.
     * A provider is `callable(SearchCriteria, SearchQuery, int $limit): array<SearchResult>`.
     *
     * @return array<string, callable>
     */
    public function providers(): array
    {
        $providers = $this->applyFilters('search_providers', [
            'post' => fn (SearchCriteria $criteria, SearchQuery $query, int $limit): array => $this->searchPosts($criteria, $query, $limit),
            'page' => fn (SearchCriteria $criteria, SearchQuery $query, int $limit): array => $this->searchPages($criteria, $query, $limit),
            'category' => fn (SearchCriteria $criteria, SearchQuery $query, int $limit): array => $this->searchCategories($query, $limit),
            'tag' => fn (SearchCriteria $criteria, SearchQuery $query, int $limit): array => $this->searchTags($query, $limit),
            'author' => fn (SearchCriteria $criteria, SearchQuery $query, int $limit): array => $this->searchAuthors($query, $limit),
        ]);

        return is_array($providers)
            ? array_filter($providers, static fn (mixed $provider, mixed $type): bool => is_string($type) && is_callable($provider), ARRAY_FILTER_USE_BOTH)
            : [];
    }

    /**
     * Display labels for every searchable type, for the search filter form.
     *
     * @return array<string, string>
     */
    public function searchableTypeLabels(): array
    {
        return array_diff_key($this->typeLabels(), array_flip($this->excludedTypes()));
    }

    /**
     * Display labels for every registered type, excluded ones included —
     * for the Settings screen that chooses which to exclude.
     *
     * @return array<string, string>
     */
    public function typeLabels(): array
    {
        $labels = $this->applyFilters('search_result_type_labels', self::TYPE_LABELS);
        $labels = is_array($labels) ? $labels : self::TYPE_LABELS;
        $all = [];

        foreach (array_keys($this->providers()) as $type) {
            $all[$type] = is_string($labels[$type] ?? null) ? $labels[$type] : ucfirst($type);
        }

        return $all;
    }

    /**
     * Content types the administrator removed from search entirely.
     *
     * @return list<string>
     */
    public function excludedTypes(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) $this->config->option('search_excluded_types', ''))),
            static fn (string $type): bool => $type !== '',
        ));
    }

    /**
     * @return list<int>
     */
    public function excludedCategoryIds(): array
    {
        return self::parseIdList((string) $this->config->option('search_excluded_category_ids', ''));
    }

    /**
     * @return list<int>
     */
    public function excludedPageIds(): array
    {
        return self::parseIdList((string) $this->config->option('search_excluded_page_ids', ''));
    }

    /**
     * @return list<int>
     */
    public static function parseIdList(string $list): array
    {
        $ids = array_map('intval', explode(',', $list));

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * Choices for the public search filter form. Only categories, tags, and
     * authors with at least one public post are offered, so a filter can
     * never be picked that is guaranteed to find nothing.
     *
     * @return array{types: array<string, string>, categories: array<string, string>, tags: array<string, string>, authors: array<string, string>}
     */
    public function filterOptions(): array
    {
        $types = $this->searchableTypeLabels();
        $postsSearchable = isset($types['post']);
        $publicPost = PublicContent::postWhere();
        $categories = [];
        $tags = [];
        $authors = [];

        if ($postsSearchable) {
            $excludedCategories = $this->excludedCategoryIds();

            foreach ($this->database->fetchAll(
                'SELECT c.id, c.slug, c.name FROM ' . $this->tablePrefix . 'categories c
                    WHERE c.trashed_at IS NULL AND EXISTS (
                        SELECT 1 FROM ' . $this->tablePrefix . 'post_categories pc
                          JOIN ' . $this->tablePrefix . "posts p ON p.id = pc.post_id
                         WHERE pc.category_id = c.id AND {$publicPost}
                    )
                 ORDER BY c.name ASC",
                $this->nowParams(),
            ) as $row) {
                if (!in_array((int) $row['id'], $excludedCategories, true)) {
                    $categories[(string) $row['slug']] = (string) $row['name'];
                }
            }

            foreach ($this->database->fetchAll(
                'SELECT t.slug, t.name FROM ' . $this->tablePrefix . 'tags t
                    WHERE t.trashed_at IS NULL AND EXISTS (
                        SELECT 1 FROM ' . $this->tablePrefix . 'post_tags pt
                          JOIN ' . $this->tablePrefix . "posts p ON p.id = pt.post_id
                         WHERE pt.tag_id = t.id AND {$publicPost}
                    )
                 ORDER BY t.name ASC",
                $this->nowParams(),
            ) as $row) {
                $tags[(string) $row['slug']] = (string) $row['name'];
            }
        }

        if ($postsSearchable || isset($types['page'])) {
            foreach ($this->publishingAuthorIds() as $authorId) {
                $author = $this->users->findById($authorId);

                if ($author !== null) {
                    $authors[$this->users->authorSlug($author)] = $author->displayName;
                }
            }

            asort($authors, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return ['types' => $types, 'categories' => $categories, 'tags' => $tags, 'authors' => $authors];
    }

    /**
     * Somewhere to go next when a search finds nothing: the newest posts and
     * the busiest categories, both honoring the search exclusions.
     *
     * @return array{posts: list<SearchResult>, categories: list<SearchResult>}
     */
    public function emptyStateSuggestions(): array
    {
        $types = $this->searchableTypeLabels();
        $posts = [];
        $categories = [];
        $excludedCategories = $this->excludedCategoryIds();

        if (isset($types['post'])) {
            $params = $this->nowParams();
            $exclusion = $this->categoryExclusionClause($excludedCategories, $params);

            foreach ($this->database->fetchAll(
                'SELECT p.id, p.title, p.slug, p.featured_image_id, p.published_at FROM ' . $this->tablePrefix . 'posts p
                    WHERE ' . $this->publicPostWhere() . $exclusion . '
                 ORDER BY p.published_at DESC, p.id DESC
                 LIMIT ' . self::SUGGESTION_COUNT,
                $params,
            ) as $row) {
                $posts[] = new SearchResult(
                    type: 'post',
                    id: (int) $row['id'],
                    title: (string) $row['title'],
                    slug: (string) $row['slug'],
                    excerpt: '',
                    featuredImageId: $row['featured_image_id'] !== null ? (int) $row['featured_image_id'] : null,
                    publishedAt: $row['published_at'] !== null ? new DateTimeImmutable((string) $row['published_at']) : null,
                    score: 0.0,
                );
            }
        }

        if (isset($types['category'])) {
            foreach ($this->database->fetchAll(
                'SELECT c.id, c.name, c.slug, COUNT(pc.post_id) AS post_count FROM ' . $this->tablePrefix . 'categories c
                    JOIN ' . $this->tablePrefix . 'post_categories pc ON pc.category_id = c.id
                    JOIN ' . $this->tablePrefix . 'posts p ON p.id = pc.post_id AND ' . PublicContent::postWhere() . '
                   WHERE c.trashed_at IS NULL
                GROUP BY c.id, c.name, c.slug
                ORDER BY post_count DESC, c.name ASC',
                $this->nowParams(),
            ) as $row) {
                if (in_array((int) $row['id'], $excludedCategories, true)) {
                    continue;
                }

                $categories[] = new SearchResult('category', (int) $row['id'], (string) $row['name'], (string) $row['slug'], '', null, null, (float) $row['post_count']);

                if (count($categories) >= self::SUGGESTION_COUNT) {
                    break;
                }
            }
        }

        return ['posts' => $posts, 'categories' => $categories];
    }

    /**
     * Public (unlike searchPosts()/searchPages()) so it's directly
     * unit-testable against SQLite — `LIKE` runs identically on both.
     *
     * @return array<int, SearchResult>
     */
    public function searchCategories(SearchQuery|string $query, int $limit): array
    {
        $query = $query instanceof SearchQuery ? $query : SearchQuery::parse($query);
        $excluded = $this->excludedCategoryIds();

        return array_values(array_filter(
            $this->searchNamedTable('categories', 'category', $query, $limit),
            static fn (SearchResult $result): bool => !in_array($result->id, $excluded, true),
        ));
    }

    /**
     * @return array<int, SearchResult>
     */
    public function searchTags(SearchQuery|string $query, int $limit): array
    {
        return $this->searchNamedTable('tags', 'tag', $query instanceof SearchQuery ? $query : SearchQuery::parse($query), $limit);
    }

    /**
     * Only non-trashed users with at least one public post — an author with
     * nothing public has an empty archive to link to, and this keeps search
     * from revealing that e.g. Subscriber-only accounts exist.
     *
     * @return array<int, SearchResult>
     */
    public function searchAuthors(SearchQuery|string $query, int $limit): array
    {
        $query = $query instanceof SearchQuery ? $query : SearchQuery::parse($query);
        $params = $this->nowParams();
        $likes = $this->likeConditions($query, ['display_name'], $params);

        if ($likes === null) {
            return [];
        }

        $rows = $this->database->fetchAll(
            'SELECT u.id FROM ' . $this->tablePrefix . 'users u
                WHERE u.trashed_at IS NULL AND ' . $likes . '
                  AND EXISTS (
                      SELECT 1 FROM ' . $this->tablePrefix . 'posts p
                       WHERE p.author_id = u.id AND ' . PublicContent::postWhere() . "
                  )
             ORDER BY u.id ASC
             LIMIT {$limit}",
            $params,
        );

        $results = [];

        foreach ($rows as $row) {
            $author = $this->users->findById((int) $row['id']);

            if ($author === null || !$query->acceptsText($author->displayName)) {
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
     * Widens each typed word with words on the site's word list that
     * contain it (partial matching) or are a letter or two away from it
     * (fuzzy matching), whichever the administrator switched on.
     */
    private function expandQuery(SearchQuery $query): SearchQuery
    {
        $partial = $this->config->option(self::PARTIAL_MATCHING_OPTION, '0') === '1';
        $fuzzy = $this->config->option(self::FUZZY_MATCHING_OPTION, '0') === '1';

        if ($this->vocabulary === null || (!$partial && !$fuzzy) || $query->terms === []) {
            return $query;
        }

        $this->vocabulary->ensureBuilt();
        $alternatives = [];

        foreach (array_slice($query->terms, 0, self::MAX_EXPANDED_TERMS) as $term) {
            $alternatives[$term] = [
                ...($partial ? $this->vocabulary->wordsContaining($term, self::PARTIAL_ALTERNATIVES_PER_TERM) : []),
                ...($fuzzy ? $this->vocabulary->similarWords($term, self::FUZZY_ALTERNATIVES_PER_TERM) : []),
            ];
        }

        return $query->withAlternatives($alternatives);
    }

    /**
     * The providers this search actually runs: administrator exclusions
     * removed, then narrowed by the visitor's type and content filters.
     * Categories/tags/authors carry no date or author of their own, and
     * pages have no categories or tags, so those drop out when filtered on.
     *
     * @return array<string, callable>
     */
    private function providersFor(SearchCriteria $criteria): array
    {
        $providers = array_diff_key($this->providers(), array_flip($this->excludedTypes()));

        if ($criteria->type !== null) {
            $providers = array_intersect_key($providers, [$criteria->type => true]);
        }

        if ($criteria->hasContentFilters()) {
            unset($providers['category'], $providers['tag'], $providers['author']);
        }

        if ($criteria->category !== null || $criteria->tag !== null) {
            unset($providers['page']);
        }

        return $providers;
    }

    /**
     * @return array{categoryId: ?int, tagId: ?int, authorId: ?int}|null
     */
    private function filtersFor(SearchCriteria $criteria): ?array
    {
        if ($this->memoCriteria !== $criteria) {
            $this->memoFilters = $this->resolveFilters($criteria);
            $this->memoCriteria = $criteria;
        }

        return $this->memoFilters;
    }

    /**
     * Resolves filter slugs to ids. Null means a filter names something that
     * doesn't exist (or is excluded), so the search can only come back empty.
     *
     * @return array{categoryId: ?int, tagId: ?int, authorId: ?int}|null
     */
    private function resolveFilters(SearchCriteria $criteria): ?array
    {
        $categoryId = null;
        $tagId = null;
        $authorId = null;

        if ($criteria->category !== null) {
            $categoryId = $this->idForSlug('categories', $criteria->category);

            if ($categoryId === null || in_array($categoryId, $this->excludedCategoryIds(), true)) {
                return null;
            }
        }

        if ($criteria->tag !== null) {
            $tagId = $this->idForSlug('tags', $criteria->tag);

            if ($tagId === null) {
                return null;
            }
        }

        if ($criteria->author !== null) {
            $authorId = $this->users->findByAuthorSlug($criteria->author)?->id;

            if ($authorId === null) {
                return null;
            }
        }

        return ['categoryId' => $categoryId, 'tagId' => $tagId, 'authorId' => $authorId];
    }

    private function idForSlug(string $tableSuffix, string $slug): ?int
    {
        $id = $this->database->fetchColumn(
            'SELECT id FROM ' . $this->tablePrefix . $tableSuffix . ' WHERE slug = :slug AND trashed_at IS NULL',
            ['slug' => $slug],
        );

        return $id !== false && $id !== null ? (int) $id : null;
    }

    /**
     * @return array<int, SearchResult>
     */
    private function searchPosts(SearchCriteria $criteria, SearchQuery $query, int $limit): array
    {
        $filters = $this->filtersFor($criteria);

        if ($filters === null) {
            return [];
        }

        $params = $this->nowParams();
        $where = $this->publicPostWhere();

        if ($filters['categoryId'] !== null) {
            $where .= ' AND EXISTS (SELECT 1 FROM ' . $this->tablePrefix . 'post_categories fc WHERE fc.post_id = p.id AND fc.category_id = :filter_category)';
            $params['filter_category'] = $filters['categoryId'];
        }

        if ($filters['tagId'] !== null) {
            $where .= ' AND EXISTS (SELECT 1 FROM ' . $this->tablePrefix . 'post_tags ft WHERE ft.post_id = p.id AND ft.tag_id = :filter_tag)';
            $params['filter_tag'] = $filters['tagId'];
        }

        $where .= $this->authorAndDateClause($criteria, $filters['authorId'], $params);
        $where .= $this->categoryExclusionClause($this->excludedCategoryIds(), $params);

        return $this->fulltextSearch('posts', 'post', $where, $params, $query, $limit);
    }

    /**
     * @return array<int, SearchResult>
     */
    private function searchPages(SearchCriteria $criteria, SearchQuery $query, int $limit): array
    {
        $filters = $this->filtersFor($criteria);

        if ($filters === null) {
            return [];
        }

        $params = ['now' => $this->now()];
        $where = PublicContent::pageWhere();
        $where .= $this->authorAndDateClause($criteria, $filters['authorId'], $params);
        $where .= $this->idListClause('p.id', 'NOT IN', $this->excludedPageIds(), 'excluded_page', $params);

        return $this->fulltextSearch('pages', 'page', $where, $params, $query, $limit);
    }

    /**
     * Distinct placeholders for each MATCH: MySQL's native prepared
     * statements reject a named placeholder used more than once.
     *
     * @param array<string, mixed> $params
     * @return array<int, SearchResult>
     */
    private function fulltextSearch(string $tableSuffix, string $type, string $where, array $params, SearchQuery $query, int $limit): array
    {
        $table = $this->tablePrefix . $tableSuffix;
        $weight = self::TITLE_WEIGHT;
        $params += [
            'ranking1' => $query->plainText(),
            'ranking2' => $query->plainText(),
            'boolean1' => $query->booleanExpression(),
            'boolean2' => $query->booleanExpression(),
        ];

        // The BOOLEAN MODE score is small next to the natural-language ones,
        // but keeps a prefix-only match (which natural language scores 0)
        // ranked by how well it matched rather than tied at zero.
        $rows = $this->database->fetchAll(
            "SELECT p.id, p.title, p.slug, p.content, p.content_format, p.excerpt, p.featured_image_id, p.published_at,
                    (MATCH(p.title, p.content) AGAINST(:ranking1 IN NATURAL LANGUAGE MODE)
                        + MATCH(p.title) AGAINST(:ranking2 IN NATURAL LANGUAGE MODE) * {$weight}
                        + MATCH(p.title, p.content) AGAINST(:boolean1 IN BOOLEAN MODE)) AS relevance_score
             FROM {$table} p
             WHERE {$where} AND MATCH(p.title, p.content) AGAINST(:boolean2 IN BOOLEAN MODE)
             ORDER BY relevance_score DESC
             LIMIT {$limit}",
            $params,
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

    private function publicPostWhere(): string
    {
        return PublicContent::postWhere();
    }

    /**
     * The date range is inclusive of both days, so "to" compares against
     * the start of the following day.
     *
     * @param array<string, mixed> $params
     */
    private function authorAndDateClause(SearchCriteria $criteria, ?int $authorId, array &$params): string
    {
        $clause = '';

        if ($authorId !== null) {
            $clause .= ' AND p.author_id = :filter_author';
            $params['filter_author'] = $authorId;
        }

        if ($criteria->dateFrom !== null) {
            $clause .= ' AND p.published_at >= :filter_from';
            $params['filter_from'] = $criteria->dateFrom->format('Y-m-d 00:00:00');
        }

        if ($criteria->dateTo !== null) {
            $clause .= ' AND p.published_at < :filter_to';
            $params['filter_to'] = $criteria->dateTo->modify('+1 day')->format('Y-m-d 00:00:00');
        }

        return $clause;
    }

    /**
     * A post filed in any excluded category is hidden, even if it is also
     * in a category that isn't excluded.
     *
     * @param list<int> $categoryIds
     * @param array<string, mixed> $params
     */
    private function categoryExclusionClause(array $categoryIds, array &$params): string
    {
        if ($categoryIds === []) {
            return '';
        }

        return ' AND NOT EXISTS (SELECT 1 FROM ' . $this->tablePrefix . 'post_categories xc WHERE xc.post_id = p.id'
            . $this->idListClause('xc.category_id', 'IN', $categoryIds, 'excluded_category', $params) . ')';
    }

    /**
     * @param list<int> $ids
     * @param array<string, mixed> $params
     */
    private function idListClause(string $column, string $operator, array $ids, string $placeholderPrefix, array &$params): string
    {
        if ($ids === []) {
            return '';
        }

        $placeholders = [];

        foreach ($ids as $index => $id) {
            $placeholders[] = ':' . $placeholderPrefix . $index;
            $params[$placeholderPrefix . $index] = $id;
        }

        return " AND {$column} {$operator} (" . implode(', ', $placeholders) . ')';
    }

    /**
     * @return array<int, SearchResult>
     */
    private function searchNamedTable(string $tableSuffix, string $type, SearchQuery $query, int $limit): array
    {
        $params = [];
        $likes = $this->likeConditions($query, ['name', 'description'], $params);

        if ($likes === null) {
            return [];
        }

        $rows = $this->database->fetchAll(
            'SELECT id, name, slug, description FROM ' . $this->tablePrefix . $tableSuffix . "
                WHERE trashed_at IS NULL AND {$likes}
             ORDER BY id ASC
             LIMIT {$limit}",
            $params,
        );

        $results = [];

        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $description = (string) ($row['description'] ?? '');

            if (!$query->acceptsText($name . ' ' . $description)) {
                continue;
            }

            $results[] = new SearchResult(
                type: $type,
                id: (int) $row['id'],
                title: $name,
                slug: (string) $row['slug'],
                excerpt: make_excerpt($description),
                featuredImageId: null,
                publishedAt: null,
                score: self::nameMatchScore($name, $description, $query),
            );
        }

        return $results;
    }

    /**
     * Every positive word must appear in at least one of $columns. Matching
     * word by word (rather than the whole query as one substring) keeps
     * "rock roll" finding "Rock & Roll"; phrase order and exclusions are
     * then checked in PHP by SearchQuery::acceptsText().
     *
     * @param list<string> $columns
     * @param array<string, mixed> $params
     */
    private function likeConditions(SearchQuery $query, array $columns, array &$params): ?string
    {
        $words = array_values(array_unique(explode(' ', $query->plainText())));
        $words = array_filter($words, static fn (string $word): bool => $word !== '');

        if ($words === []) {
            return null;
        }

        $conditions = [];

        foreach (array_values($words) as $wordIndex => $word) {
            $alternatives = [];

            foreach ($columns as $columnIndex => $column) {
                $name = 'like_' . $wordIndex . '_' . $columnIndex;
                $alternatives[] = "{$column} LIKE :{$name} ESCAPE '!'";
                $params[$name] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word) . '%';
            }

            $conditions[] = '(' . implode(' OR ', $alternatives) . ')';
        }

        return implode(' AND ', $conditions);
    }

    /**
     * @return list<int>
     */
    private function publishingAuthorIds(): array
    {
        return array_map('intval', array_column($this->database->fetchAll(
            'SELECT DISTINCT p.author_id FROM ' . $this->tablePrefix . 'posts p
                JOIN ' . $this->tablePrefix . 'users u ON u.id = p.author_id AND u.trashed_at IS NULL
                WHERE ' . PublicContent::postWhere(),
            $this->nowParams(),
        ), 'author_id'));
    }

    /**
     * @return array{now: string, now_unpublish: string}
     */
    private function nowParams(): array
    {
        return ['now' => $this->now(), 'now_unpublish' => $this->now()];
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $this->hooks !== null ? $this->hooks->applyFilters($hook, $value, ...$args) : $value;
    }

    private static function compareForSort(SearchResult $a, SearchResult $b, string $sort): int
    {
        if ($sort === SearchCriteria::SORT_TITLE) {
            return strnatcasecmp(mb_strtolower($a->title), mb_strtolower($b->title));
        }

        if ($a->publishedAt === null || $b->publishedAt === null) {
            return ($a->publishedAt === null) <=> ($b->publishedAt === null);
        }

        return $sort === SearchCriteria::SORT_OLDEST
            ? $a->publishedAt <=> $b->publishedAt
            : $b->publishedAt <=> $a->publishedAt;
    }

    /**
     * Compared on normalized words, so punctuation in a name ("Rock &
     * Roll") doesn't stop an otherwise exact match from scoring as one.
     */
    private static function nameMatchScore(string $name, string $description, SearchQuery $query): float
    {
        $normalizedName = SearchQuery::normalize($name);
        $needle = $query->plainText();

        if ($normalizedName === $needle) {
            return self::SCORE_EXACT_NAME;
        }

        if (str_starts_with($normalizedName, $needle)) {
            return self::SCORE_NAME_STARTS_WITH;
        }

        if (str_contains($normalizedName, $needle)) {
            return self::SCORE_NAME_CONTAINS;
        }

        return self::SCORE_DESCRIPTION_CONTAINS;
    }
}
