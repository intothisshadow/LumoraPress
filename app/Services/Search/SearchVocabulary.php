<?php

/**
 * The search word list behind "Did you mean?", fuzzy matching, and partial matching.
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
 * Words from public post and page titles and category, tag, and author
 * names, each weighted by how many of them it appears in. Titles and names
 * rather than full content keep a rebuild to one quick request even on a
 * large site, and give suggestions that read like the site's own wording.
 *
 * MySQL's FULLTEXT index can't be asked for similar or containing words,
 * and scanning every title per search would read every post row, so this
 * table exists purely to answer those questions from a small indexed list.
 * Words from content saved later are added as it is saved; removed words
 * linger until the next rebuild, which is harmless because every use of
 * the list still searches the real, public content.
 */
final class SearchVocabulary
{
    public const BUILT_AT_OPTION = 'search_vocabulary_built_at';

    private const MIN_WORD_LENGTH = 3;

    private const BATCH_SIZE = 500;

    private const TITLE_BATCH_SIZE = 1000;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly PressConfig $config,
    ) {
    }

    public function isBuilt(): bool
    {
        return (string) $this->config->option(self::BUILT_AT_OPTION, '') !== '';
    }

    public function builtAt(): ?DateTimeImmutable
    {
        $value = (string) $this->config->option(self::BUILT_AT_OPTION, '');

        return $value !== '' ? new DateTimeImmutable($value) : null;
    }

    public function ensureBuilt(): void
    {
        if (!$this->isBuilt()) {
            $this->rebuild();
        }
    }

    /**
     * Replaces the whole list with the words from everything currently
     * public. Returns the number of distinct words.
     */
    public function rebuild(): int
    {
        $counts = [];

        foreach ($this->publicTexts() as $text) {
            foreach (self::uniqueWords($text) as $word) {
                $counts[$word] = ($counts[$word] ?? 0) + 1;
            }
        }

        $this->database->transaction(function () use ($counts): void {
            $this->database->execute('DELETE FROM ' . $this->table());

            foreach (array_chunk($counts, self::BATCH_SIZE, true) as $chunk) {
                $this->insertWords($chunk);
            }
        });

        $this->config->setOption(self::BUILT_AT_OPTION, (new DateTimeImmutable())->format('Y-m-d H:i:s'));

        return count($counts);
    }

    /**
     * Adds one saved title or name. Skipped until the first rebuild, which
     * would replace anything added before it anyway.
     */
    public function addText(string $text): void
    {
        if (!$this->isBuilt()) {
            return;
        }

        foreach (array_chunk(self::uniqueWords($text), self::BATCH_SIZE) as $words) {
            $params = [];
            $placeholders = self::placeholders($words, 'word', $params);

            $existing = array_map('strval', array_column($this->database->fetchAll(
                'SELECT term FROM ' . $this->table() . " WHERE term IN ({$placeholders})",
                $params,
            ), 'term'));

            if ($existing !== []) {
                $updateParams = [];
                $this->database->execute(
                    'UPDATE ' . $this->table() . ' SET weight = weight + 1 WHERE term IN (' . self::placeholders($existing, 'existing', $updateParams) . ')',
                    $updateParams,
                );
            }

            $missing = array_diff($words, $existing);

            if ($missing !== []) {
                try {
                    $this->insertWords(array_fill_keys($missing, 1));
                } catch (PDOException) {
                    // Another request added one of these words between the
                    // SELECT and INSERT; losing a single weight increment is harmless.
                }
            }
        }
    }

    public function size(): int
    {
        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM ' . $this->table());
    }

    public function contains(string $word): bool
    {
        return $this->database->fetchColumn('SELECT 1 FROM ' . $this->table() . ' WHERE term = :term', ['term' => $word]) !== null;
    }

    /**
     * The best-known word within editing distance of $word, or null. Only
     * words sharing its first letter are considered: typos rarely change
     * the first letter, and it keeps the candidate set an index range scan.
     */
    public function closestWord(string $word): ?string
    {
        return $this->similarWords($word, 1)[0] ?? null;
    }

    /**
     * Known words within editing distance of $word (not $word itself),
     * closest first, then most common.
     *
     * @return list<string>
     */
    public function similarWords(string $word, int $limit): array
    {
        $length = mb_strlen($word);

        if ($length < self::MIN_WORD_LENGTH) {
            return [];
        }

        $maxDistance = $length < 5 ? 1 : 2;
        $scored = [];

        foreach ($this->database->fetchAll(
            'SELECT term, weight FROM ' . $this->table() . " WHERE term LIKE :prefix ESCAPE '!'",
            ['prefix' => self::escapeLike(mb_substr($word, 0, 1)) . '%'],
        ) as $row) {
            $term = (string) $row['term'];

            if ($term === $word || abs(mb_strlen($term) - $length) > $maxDistance) {
                continue;
            }

            $distance = self::distance($word, $term);

            if ($distance <= $maxDistance) {
                $scored[] = ['term' => $term, 'distance' => $distance, 'weight' => (int) $row['weight']];
            }
        }

        usort($scored, static fn (array $a, array $b): int => [$a['distance'], $b['weight'], $a['term']] <=> [$b['distance'], $a['weight'], $b['term']]);

        return array_slice(array_column($scored, 'term'), 0, max(0, $limit));
    }

    /**
     * Known words containing $fragment somewhere other than as the whole
     * word, most common first — "graph" finds "photography".
     *
     * @return list<string>
     */
    public function wordsContaining(string $fragment, int $limit): array
    {
        if (mb_strlen($fragment) < self::MIN_WORD_LENGTH) {
            return [];
        }

        $limit = max(1, $limit);

        return array_map('strval', array_column($this->database->fetchAll(
            'SELECT term FROM ' . $this->table() . " WHERE term LIKE :fragment ESCAPE '!' AND term <> :exact
             ORDER BY weight DESC, term ASC
             LIMIT {$limit}",
            ['fragment' => '%' . self::escapeLike($fragment) . '%', 'exact' => $fragment],
        ), 'term'));
    }

    /**
     * Levenshtein distance by character, not byte, so an accented letter
     * counts as one edit.
     */
    public static function distance(string $a, string $b): int
    {
        if (strlen($a) === mb_strlen($a) && strlen($b) === mb_strlen($b)) {
            return levenshtein($a, $b);
        }

        $left = mb_str_split($a);
        $right = mb_str_split($b);
        $previous = range(0, count($right));

        foreach ($left as $i => $leftChar) {
            $current = [$i + 1];

            foreach ($right as $j => $rightChar) {
                $current[] = min(
                    $previous[$j + 1] + 1,
                    $current[$j] + 1,
                    $previous[$j] + ($leftChar === $rightChar ? 0 : 1),
                );
            }

            $previous = $current;
        }

        return $previous[count($right)];
    }

    /**
     * Lowercased words of at least three characters, digits-only ones
     * dropped (years and episode numbers make poor spelling suggestions).
     *
     * @return list<string>
     */
    public static function uniqueWords(string $text): array
    {
        $normalized = SearchQuery::normalize($text);

        if ($normalized === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            explode(' ', $normalized),
            static fn (string $word): bool => mb_strlen($word) >= self::MIN_WORD_LENGTH && !ctype_digit($word),
        )));
    }

    /**
     * @return iterable<string>
     */
    private function publicTexts(): iterable
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ([
            ['posts', PublicContent::postWhere(), ['now' => $now, 'now_unpublish' => $now]],
            ['pages', PublicContent::pageWhere(), ['now' => $now]],
        ] as [$tableSuffix, $where, $params]) {
            $lastId = 0;

            do {
                $rows = $this->database->fetchAll(
                    'SELECT p.id, p.title FROM ' . $this->tablePrefix . $tableSuffix . " p
                        WHERE {$where} AND p.id > :last_id
                     ORDER BY p.id ASC
                     LIMIT " . self::TITLE_BATCH_SIZE,
                    $params + ['last_id' => $lastId],
                );

                foreach ($rows as $row) {
                    $lastId = (int) $row['id'];

                    yield (string) $row['title'];
                }
            } while (count($rows) === self::TITLE_BATCH_SIZE);
        }

        foreach (['categories', 'tags'] as $tableSuffix) {
            foreach ($this->database->fetchAll('SELECT name FROM ' . $this->tablePrefix . $tableSuffix . ' WHERE trashed_at IS NULL') as $row) {
                yield (string) $row['name'];
            }
        }

        foreach ($this->database->fetchAll(
            'SELECT DISTINCT u.display_name FROM ' . $this->tablePrefix . 'users u
                JOIN ' . $this->tablePrefix . 'posts p ON p.author_id = u.id
               WHERE u.trashed_at IS NULL AND ' . PublicContent::postWhere(),
            ['now' => $now, 'now_unpublish' => $now],
        ) as $row) {
            yield (string) $row['display_name'];
        }
    }

    /**
     * @param array<string, int> $weights word => weight
     */
    private function insertWords(array $weights): void
    {
        if ($weights === []) {
            return;
        }

        $values = [];
        $params = [];
        $index = 0;

        foreach ($weights as $word => $weight) {
            $values[] = "(:term{$index}, :weight{$index})";
            $params['term' . $index] = (string) $word;
            $params['weight' . $index] = $weight;
            $index++;
        }

        $this->database->execute('INSERT INTO ' . $this->table() . ' (term, weight) VALUES ' . implode(', ', $values), $params);
    }

    /**
     * @param list<string> $values
     * @param array<string, mixed> $params
     */
    private static function placeholders(array $values, string $prefix, array &$params): string
    {
        $names = [];

        foreach (array_values($values) as $index => $value) {
            $names[] = ':' . $prefix . $index;
            $params[$prefix . $index] = $value;
        }

        return implode(', ', $names);
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function table(): string
    {
        return $this->tablePrefix . 'search_terms';
    }
}
