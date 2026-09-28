<?php

/**
 * Short-lived file cache of the matches a search found, so a repeated search skips the database.
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
use LumoraPress\Core\PressConfig;
use LumoraPress\Models\SearchResult;

/**
 * Holds only what signed-out visitors may see (every search query is
 * limited to public content), so one entry can serve everyone. Files
 * rather than a table: entries are disposable, and a missing or
 * unwritable directory just means every search runs live.
 *
 * Content changes empty the whole cache. Anything that changes without a
 * save (a post's scheduled publish or unpublish time passing) is bounded
 * by the entry lifetime instead, which is why it stays short.
 */
final class SearchResultCache
{
    public const ENABLED_OPTION = 'search_cache_enabled';

    public const LIFETIME_OPTION = 'search_cache_lifetime';

    public const DEFAULT_LIFETIME = 300;

    private const MIN_LIFETIME = 30;

    private const MAX_LIFETIME = 86400;

    /** Bounds disk use when visitors (or bots) search for many different terms. */
    private const MAX_ENTRIES = 300;

    private const EXTENSION = '.cache';

    public function __construct(
        private readonly string $directory,
        private readonly PressConfig $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->option(self::ENABLED_OPTION, '1') !== '0';
    }

    public function lifetime(): int
    {
        $seconds = (int) $this->config->option(self::LIFETIME_OPTION, (string) self::DEFAULT_LIFETIME);

        return max(self::MIN_LIFETIME, min(self::MAX_LIFETIME, $seconds));
    }

    /**
     * @param array<string, mixed> $parts everything that can change what is found
     */
    public static function keyFor(array $parts): string
    {
        return sha1(json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
    }

    /**
     * @param callable(): list<SearchResult> $compute
     * @return list<SearchResult>
     */
    public function remember(string $key, callable $compute): array
    {
        $cached = $this->read($key);

        if ($cached !== null) {
            return $cached;
        }

        $results = $compute();
        $this->write($key, $results);

        return $results;
    }

    /**
     * Returns how many entries were removed.
     */
    public function clear(): int
    {
        $removed = 0;

        foreach ($this->files() as $file) {
            if (unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @return array{entries: int, bytes: int}
     */
    public function stats(): array
    {
        $bytes = 0;
        $files = $this->files();

        foreach ($files as $file) {
            $bytes += (int) filesize($file);
        }

        return ['entries' => count($files), 'bytes' => $bytes];
    }

    /**
     * Whether entries can be stored at all, for the diagnostics screen.
     */
    public function isWritable(): bool
    {
        return is_dir($this->directory) ? is_writable($this->directory) : is_writable(dirname($this->directory));
    }

    /**
     * @return list<SearchResult>|null
     */
    private function read(string $key): ?array
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return null;
        }

        // JSON rather than serialize(): reading it back can never instantiate
        // an arbitrary class, and a corrupt file fails quietly instead of warning.
        $payload = json_decode((string) file_get_contents($path), true);
        $results = is_array($payload) && is_array($payload['results'] ?? null) && (int) ($payload['expires'] ?? 0) >= time()
            ? array_map(self::decode(...), array_values($payload['results']))
            : [null];

        // Anything unexpected in the file means it isn't ours: drop it whole rather than trust part of it.
        if (in_array(null, $results, true)) {
            unlink($path);

            return null;
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private static function encode(SearchResult $result): array
    {
        return [
            'type' => $result->type,
            'id' => $result->id,
            'title' => $result->title,
            'slug' => $result->slug,
            'excerpt' => $result->excerpt,
            'featuredImageId' => $result->featuredImageId,
            'publishedAt' => $result->publishedAt?->format(DateTimeImmutable::ATOM),
            'score' => $result->score,
            'url' => $result->url,
        ];
    }

    private static function decode(mixed $row): ?SearchResult
    {
        if (
            !is_array($row)
            || !is_string($row['type'] ?? null) || !is_int($row['id'] ?? null)
            || !is_string($row['title'] ?? null) || !is_string($row['slug'] ?? null)
            || !is_string($row['excerpt'] ?? null) || !is_numeric($row['score'] ?? null)
        ) {
            return null;
        }

        foreach (['featuredImageId' => 'is_int', 'publishedAt' => 'is_string', 'url' => 'is_string'] as $key => $isValid) {
            if (!array_key_exists($key, $row) || ($row[$key] !== null && !$isValid($row[$key]))) {
                return null;
            }
        }

        try {
            $publishedAt = $row['publishedAt'] !== null ? new DateTimeImmutable($row['publishedAt']) : null;
        } catch (\Exception) {
            return null;
        }

        return new SearchResult($row['type'], $row['id'], $row['title'], $row['slug'], $row['excerpt'], $row['featuredImageId'], $publishedAt, (float) $row['score'], $row['url']);
    }

    /**
     * @param list<SearchResult> $results
     */
    private function write(string $key, array $results): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            return;
        }

        if (!is_writable($this->directory)) {
            return;
        }

        $this->prune();

        // Written aside then renamed, so a concurrent reader never sees half a file.
        $temporary = $this->directory . '/' . bin2hex(random_bytes(6)) . '.tmp';

        if (file_put_contents($temporary, json_encode(['expires' => time() + $this->lifetime(), 'results' => array_map(self::encode(...), $results)], JSON_PARTIAL_OUTPUT_ON_ERROR)) === false) {
            return;
        }

        if (!rename($temporary, $this->path($key))) {
            unlink($temporary);
        }
    }

    /**
     * Drops expired entries, then the oldest ones, so a write always
     * leaves room under the entry limit.
     */
    private function prune(): void
    {
        $oldest = time() - $this->lifetime();
        $live = [];

        foreach ($this->files() as $file) {
            $modified = (int) filemtime($file);

            if ($modified < $oldest) {
                unlink($file);
            } else {
                $live[$file] = $modified;
            }
        }

        if (count($live) < self::MAX_ENTRIES) {
            return;
        }

        asort($live);

        foreach (array_slice(array_keys($live), 0, count($live) - self::MAX_ENTRIES + 1) as $file) {
            unlink($file);
        }
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        return is_dir($this->directory) ? (glob($this->directory . '/*' . self::EXTENSION) ?: []) : [];
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . preg_replace('/[^a-f0-9]/', '', $key) . self::EXTENSION;
    }
}
