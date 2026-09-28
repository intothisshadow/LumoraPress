<?php

/**
 * A visitor's search text parsed into phrases, terms, and exclusions.
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

/**
 * Supports `"exact phrase"` and `-excluded` words; every other word is
 * prefix-matched. Words are reduced to letters, digits, and underscores,
 * so the BOOLEAN MODE expression built here never carries a raw MySQL
 * operator from the visitor — unbalanced quotes or stray `+`/`(` would
 * otherwise be a syntax error rather than a search.
 */
final class SearchQuery
{
    /** Caps how much work one query string can ask the FULLTEXT engine for. */
    private const MAX_WORDS = 32;

    private const MAX_WORD_LENGTH = 64;

    /**
     * @param list<string> $phrases Multi-word phrases, words joined by one space.
     * @param list<string> $terms
     * @param list<string> $excluded
     * @param array<string, list<string>> $alternatives Extra words a term may match instead (fuzzy/partial matching).
     */
    private function __construct(
        public readonly array $phrases,
        public readonly array $terms,
        public readonly array $excluded,
        public readonly array $alternatives = [],
    ) {
    }

    public static function parse(string $query): self
    {
        $phrases = [];
        $terms = [];
        $excluded = [];
        $budget = self::MAX_WORDS;

        $remainder = preg_replace_callback(
            '/"([^"]*)"/u',
            static function (array $match) use (&$phrases, &$terms, &$budget): string {
                $words = array_slice(self::words($match[1]), 0, max(0, $budget));
                $budget -= count($words);

                // A one-word "phrase" is just a term — quoting it shouldn't
                // switch off prefix matching for no visible reason.
                if (count($words) > 1) {
                    $phrases[] = implode(' ', $words);
                } elseif ($words !== []) {
                    $terms[] = $words[0];
                }

                return ' ';
            },
            $query,
        ) ?? $query;

        foreach (preg_split('/\s+/u', str_replace('"', ' ', $remainder), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $isExcluded = str_starts_with($token, '-');

            foreach (self::words($isExcluded ? substr($token, 1) : $token) as $word) {
                if ($budget-- <= 0) {
                    break 2;
                }

                if ($isExcluded) {
                    $excluded[] = $word;
                } else {
                    $terms[] = $word;
                }
            }
        }

        return new self(
            array_values(array_unique($phrases)),
            array_values(array_unique($terms)),
            array_values(array_unique($excluded)),
        );
    }

    /**
     * A copy where each term in $alternatives may also match those words.
     * Alternatives only widen what matches; ranking still uses the words
     * the visitor typed, so an exact match outranks a stand-in.
     *
     * @param array<string, list<string>> $alternatives term => words
     */
    public function withAlternatives(array $alternatives): self
    {
        $kept = [];

        foreach ($this->terms as $term) {
            $words = array_values(array_diff(array_unique($alternatives[$term] ?? []), [$term], $this->excluded));

            if ($words !== []) {
                $kept[$term] = $words;
            }
        }

        return new self($this->phrases, $this->terms, $this->excluded, $kept);
    }

    /**
     * The query written back out as search text, with any word in
     * $replacements swapped — how "Did you mean?" builds its suggestion.
     *
     * @param array<string, string> $replacements word => replacement
     */
    public function toQueryString(array $replacements = []): string
    {
        $swap = static fn (string $word): string => $replacements[$word] ?? $word;
        $parts = [];

        foreach ($this->phrases as $phrase) {
            $parts[] = '"' . implode(' ', array_map($swap, explode(' ', $phrase))) . '"';
        }

        foreach ($this->terms as $term) {
            $parts[] = $swap($term);
        }

        foreach ($this->excluded as $term) {
            $parts[] = '-' . $term;
        }

        return implode(' ', $parts);
    }

    /**
     * Every word in the phrases and terms, once each.
     *
     * @return list<string>
     */
    public function positiveWords(): array
    {
        $words = [];

        foreach ([...$this->phrases, ...$this->terms] as $part) {
            array_push($words, ...explode(' ', $part));
        }

        return array_values(array_unique($words));
    }

    public function hasPositiveTerms(): bool
    {
        return $this->phrases !== [] || $this->terms !== [];
    }

    /**
     * The expression for `MATCH ... AGAINST(... IN BOOLEAN MODE)`: phrases
     * required, plain terms optional but prefix-matched, exclusions removed.
     */
    public function booleanExpression(): string
    {
        $parts = [];

        foreach ($this->phrases as $phrase) {
            $parts[] = '+"' . $phrase . '"';
        }

        foreach ($this->terms as $term) {
            $parts[] = $term . '*';

            foreach ($this->alternatives[$term] ?? [] as $alternative) {
                $parts[] = $alternative;
            }
        }

        foreach ($this->excluded as $term) {
            $parts[] = '-' . $term;
        }

        return implode(' ', $parts);
    }

    /**
     * Every positive word, for NATURAL LANGUAGE MODE ranking and for the
     * `LIKE` match used on names (categories, tags, authors).
     */
    public function plainText(): string
    {
        return implode(' ', [...$this->phrases, ...$this->terms]);
    }

    /**
     * @return list<string>
     */
    public function highlightTerms(): array
    {
        return [...$this->phrases, ...$this->terms];
    }

    /**
     * Whether $text (a name, title, or description) satisfies this query's
     * phrases and exclusions — the non-FULLTEXT equivalent of the boolean
     * expression, for `LIKE`-matched rows.
     */
    public function acceptsText(string $text): bool
    {
        $normalized = ' ' . self::normalize($text) . ' ';

        foreach ($this->phrases as $phrase) {
            if (!str_contains($normalized, ' ' . $phrase . ' ')) {
                return false;
            }
        }

        foreach ($this->excluded as $term) {
            if (str_contains($normalized, ' ' . $term . ' ')) {
                return false;
            }
        }

        return true;
    }

    /**
     * $text lowercased and reduced to single-spaced words, the same form
     * phrases and terms are held in.
     */
    public static function normalize(string $text): string
    {
        return implode(' ', self::words($text));
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_map(
            static fn (string $word): string => mb_substr($word, 0, self::MAX_WORD_LENGTH),
            $words,
        ));
    }
}
