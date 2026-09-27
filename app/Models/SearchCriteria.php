<?php

/**
 * What a visitor asked the site search for: query text, filters, sort, and page.
 *
 * @package LumoraPress
 * @subpackage Models
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.19.0
 */

declare(strict_types=1);

namespace LumoraPress\Models;

use DateTimeImmutable;

/**
 * Filters are held as slugs (not ids) because they come straight from a
 * shareable search URL; SearchService resolves them. `extra` carries the
 * values of any query parameters a plugin registered through the
 * `search_filter_params` filter, already reduced to plain strings.
 */
final class SearchCriteria
{
    public const SORT_RELEVANCE = 'relevance';

    public const SORT_NEWEST = 'newest';

    public const SORT_OLDEST = 'oldest';

    public const SORT_TITLE = 'title';

    public const SORTS = [self::SORT_RELEVANCE, self::SORT_NEWEST, self::SORT_OLDEST, self::SORT_TITLE];

    /** The query-string keys core reads; a plugin's own filter params must not reuse them. */
    public const CORE_PARAMS = ['q', 'paged', 'type', 'category', 'tag', 'author', 'from', 'to', 'sort'];

    /**
     * @param array<string, string> $extra
     */
    public function __construct(
        public readonly string $query,
        public readonly int $page = 1,
        public readonly ?string $type = null,
        public readonly ?string $category = null,
        public readonly ?string $tag = null,
        public readonly ?string $author = null,
        public readonly ?DateTimeImmutable $dateFrom = null,
        public readonly ?DateTimeImmutable $dateTo = null,
        public readonly string $sort = self::SORT_RELEVANCE,
        public readonly array $extra = [],
    ) {
    }

    /**
     * Anything malformed is dropped rather than rejected, so a hand-edited
     * or stale search URL still returns a search instead of an error.
     *
     * @param array<string, mixed> $params Usually $_GET.
     * @param list<string> $extraParams Plugin-registered parameter names.
     */
    public static function fromRequest(array $params, array $extraParams = []): self
    {
        $extra = [];

        foreach ($extraParams as $name) {
            if (!in_array($name, self::CORE_PARAMS, true) && is_string($params[$name] ?? null) && trim($params[$name]) !== '') {
                $extra[$name] = mb_substr(trim($params[$name]), 0, 200);
            }
        }

        $sort = self::stringParam($params, 'sort');

        return new self(
            query: mb_substr(trim(self::stringParam($params, 'q') ?? ''), 0, 200),
            page: max(1, (int) (is_scalar($params['paged'] ?? null) ? $params['paged'] : 1)),
            type: self::slugParam($params, 'type'),
            category: self::slugParam($params, 'category'),
            tag: self::slugParam($params, 'tag'),
            author: self::slugParam($params, 'author'),
            dateFrom: self::dateParam($params, 'from'),
            dateTo: self::dateParam($params, 'to'),
            sort: in_array($sort, self::SORTS, true) ? $sort : self::SORT_RELEVANCE,
            extra: $extra,
        );
    }

    /**
     * True when a filter narrows results to dated, authored content —
     * categories, tags, and authors themselves can't match one.
     */
    public function hasContentFilters(): bool
    {
        return $this->category !== null || $this->tag !== null || $this->author !== null
            || $this->dateFrom !== null || $this->dateTo !== null;
    }

    public function hasFilters(): bool
    {
        return $this->type !== null || $this->hasContentFilters() || $this->extra !== [];
    }

    /**
     * The criteria as search URL query parameters, defaults omitted.
     *
     * @return array<string, string>
     */
    public function toQueryParams(): array
    {
        return array_filter([
            'q' => $this->query,
            'type' => $this->type ?? '',
            'category' => $this->category ?? '',
            'tag' => $this->tag ?? '',
            'author' => $this->author ?? '',
            'from' => $this->dateFrom?->format('Y-m-d') ?? '',
            'to' => $this->dateTo?->format('Y-m-d') ?? '',
            'sort' => $this->sort !== self::SORT_RELEVANCE ? $this->sort : '',
            ...$this->extra,
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function stringParam(array $params, string $name): ?string
    {
        return is_string($params[$name] ?? null) ? $params[$name] : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function slugParam(array $params, string $name): ?string
    {
        $value = trim(self::stringParam($params, $name) ?? '');

        return preg_match('/^[\p{L}\p{N}_-]{1,190}$/u', $value) === 1 ? $value : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function dateParam(array $params, string $name): ?DateTimeImmutable
    {
        $value = trim(self::stringParam($params, $name) ?? '');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        // Round-tripping rejects overflowed dates such as 2026-02-31.
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
