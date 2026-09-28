<?php

/**
 * Search theme API: search_result_type_label(), search_filters_form(), search_did_you_mean(), and search_empty_state().
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.19.0
 */

declare(strict_types=1);

use LumoraPress\Models\SearchCriteria;
use LumoraPress\Models\SearchResult;
use LumoraPress\Services\SearchService;

/**
 * The renderers take the `$search` bundle SiteController::search() passes
 * to search.php, so a filter added in core reaches every theme without the
 * theme naming it.
 */

if (!function_exists('search_result_type_label')) {
    /**
     * "Post", "Page", "Category", … — or a plugin's own label for a content
     * type it registered through `search_providers`.
     */
    function search_result_type_label(SearchResult $result): string
    {
        $labels = apply_filters('search_result_type_labels', SearchService::TYPE_LABELS);

        return is_array($labels) && is_string($labels[$result->type] ?? null)
            ? $labels[$result->type]
            : ucfirst($result->type);
    }
}

if (!function_exists('search_filters_form')) {
    /**
     * The "Refine search" panel: query, content type, category, tag,
     * author, date range, and sort. Starts open when any of them is in use.
     * Plugins add fields inside the form on the `search_filter_fields` action.
     *
     * @param array<string, mixed> $search
     */
    function search_filters_form(array $search): void
    {
        $criteria = $search['criteria'] ?? null;

        if (!$criteria instanceof SearchCriteria) {
            return;
        }

        $options = is_array($search['filterOptions'] ?? null) ? $search['filterOptions'] : [];
        $types = is_array($options['types'] ?? null) ? $options['types'] : [];
        $categories = is_array($options['categories'] ?? null) ? $options['categories'] : [];
        $tags = is_array($options['tags'] ?? null) ? $options['tags'] : [];
        $authors = is_array($options['authors'] ?? null) ? $options['authors'] : [];
        $isOpen = $criteria->hasFilters() || $criteria->sort !== SearchCriteria::SORT_RELEVANCE;

        $select = static function (string $name, string $label, string $allLabel, array $choices, ?string $current): void {
            $id = 'lp-search-filter-' . $name;

            echo '<p class="lp-search-filters__field">';
            echo '<label class="lp-search-filters__label" for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
            echo '<select class="lp-search-filters__select" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">';

            if ($allLabel !== '') {
                echo '<option value="">' . esc_html($allLabel) . '</option>';
            }

            foreach ($choices as $value => $text) {
                $value = (string) $value;
                echo '<option value="' . esc_attr($value) . '"' . ($value === $current ? ' selected' : '') . '>' . esc_html((string) $text) . '</option>';
            }

            echo '</select></p>';
        };

        $date = static function (string $name, string $label, ?DateTimeImmutable $current): void {
            $id = 'lp-search-filter-' . $name;

            echo '<p class="lp-search-filters__field lp-search-filters__field--date">';
            echo '<label class="lp-search-filters__label" for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
            echo '<input class="lp-search-filters__input" type="date" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($current?->format('Y-m-d') ?? '') . '">';
            echo '</p>';
        };

        echo '<details class="lp-search-filters"' . ($isOpen ? ' open' : '') . '>';
        echo '<summary class="lp-search-filters__toggle">Refine search</summary>';
        echo '<form class="lp-search-filters__form" role="search" method="get" action="' . esc_url(site_url('search')) . '">';
        echo '<div class="lp-search-filters__grid">';

        echo '<p class="lp-search-filters__field lp-search-filters__field--query">';
        echo '<label class="lp-search-filters__label" for="lp-search-filter-q">Search for</label>';
        echo '<input class="lp-search-filters__input" type="search" id="lp-search-filter-q" name="q" value="' . esc_attr($criteria->query) . '">';
        echo '</p>';

        if (count($types) > 1) {
            $select('type', 'Content type', 'All content', $types, $criteria->type);
        }

        if ($categories !== []) {
            $select('category', 'Category', 'Any category', $categories, $criteria->category);
        }

        if ($tags !== []) {
            $select('tag', 'Tag', 'Any tag', $tags, $criteria->tag);
        }

        if (count($authors) > 1) {
            $select('author', 'Author', 'Any author', $authors, $criteria->author);
        }

        $date('from', 'Published from', $criteria->dateFrom);
        $date('to', 'Published until', $criteria->dateTo);

        $select('sort', 'Sort by', '', [
            SearchCriteria::SORT_RELEVANCE => 'Best match',
            SearchCriteria::SORT_NEWEST => 'Newest first',
            SearchCriteria::SORT_OLDEST => 'Oldest first',
            SearchCriteria::SORT_TITLE => 'Title (A–Z)',
        ], $criteria->sort);

        do_action('search_filter_fields', $criteria);

        echo '</div>';
        echo '<p class="lp-search-filters__hint">Put words in "quotes" to find an exact phrase, or put a minus sign before a word (-word) to leave out results containing it.</p>';
        echo '<div class="lp-search-filters__actions">';
        echo '<button class="lp-search-filters__button" type="submit">Search</button>';

        if ($criteria->hasFilters() || $criteria->sort !== SearchCriteria::SORT_RELEVANCE) {
            echo '<a class="lp-search-filters__reset" href="' . esc_url(site_url('search') . '?' . http_build_query(['q' => $criteria->query])) . '">Clear filters</a>';
        }

        echo '</div></form></details>';
    }
}

if (!function_exists('search_did_you_mean')) {
    /**
     * "Did you mean …?" with a link to the corrected search, keeping the
     * visitor's filters. Echoes nothing when there's no better spelling.
     *
     * @param array<string, mixed> $search
     */
    function search_did_you_mean(array $search): void
    {
        $suggestion = $search['didYouMean'] ?? null;
        $criteria = $search['criteria'] ?? null;

        if (!is_string($suggestion) || $suggestion === '' || !$criteria instanceof SearchCriteria) {
            return;
        }

        $url = site_url('search') . '?' . http_build_query($criteria->withQuery($suggestion)->toQueryParams());

        echo '<p class="lp-search-did-you-mean">Did you mean <a class="lp-search-did-you-mean__link" href="' . esc_url($url) . '">'
            . esc_html($suggestion) . '</a>?</p>';
    }
}

if (!function_exists('search_empty_state')) {
    /**
     * What search.php shows instead of results: why nothing matched (too
     * short, or too narrow) plus the newest posts and busiest categories.
     *
     * @param array<string, mixed> $search
     */
    function search_empty_state(array $search): void
    {
        $criteria = $search['criteria'] ?? null;
        $query = $criteria instanceof SearchCriteria ? $criteria->query : (string) ($search['query'] ?? '');
        $suggestions = is_array($search['suggestions'] ?? null) ? $search['suggestions'] : [];
        $posts = is_array($suggestions['posts'] ?? null) ? $suggestions['posts'] : [];
        $categories = is_array($suggestions['categories'] ?? null) ? $suggestions['categories'] : [];

        echo '<div class="lp-search-empty">';

        if ($query === '') {
            echo '<p class="lp-empty-state">Enter a word or phrase to search for.</p>';
        } else {
            echo '<p class="lp-empty-state">No results found.</p>';
            echo '<ul class="lp-search-empty__tips">';
            echo '<li>Check the spelling, or try a shorter or more general word.</li>';

            if ($criteria instanceof SearchCriteria && $criteria->hasFilters()) {
                echo '<li><a href="' . esc_url(site_url('search') . '?' . http_build_query(['q' => $query])) . '">Search again without filters</a>.</li>';
            }

            echo '</ul>';
        }

        $renderList = static function (string $heading, array $items): void {
            if ($items === []) {
                return;
            }

            echo '<section class="lp-search-empty__section">';
            echo '<h2 class="lp-search-empty__heading">' . esc_html($heading) . '</h2>';
            echo '<ul class="lp-search-empty__list">';

            foreach ($items as $item) {
                if ($item instanceof SearchResult) {
                    echo '<li class="lp-search-empty__item"><a href="' . esc_url(search_result_permalink($item)) . '">' . esc_html($item->title) . '</a></li>';
                }
            }

            echo '</ul></section>';
        };

        $renderList('Recent posts', $posts);
        $renderList('Popular categories', $categories);

        echo '</div>';
    }
}
