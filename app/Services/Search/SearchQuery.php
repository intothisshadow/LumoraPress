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
     */
    private function __construct(
        public readonly array $phrases,
        public readonly array $terms,
        public readonly array $excluded,
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
