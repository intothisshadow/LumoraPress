<?php

/**
 * The framework-agnostic Cache API (LP-037): one instance per request, delegating actual purging to whichever driver is active.
 *
 * @package LumoraPress
 * @subpackage Cache
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Cache;

use LumoraPress\Core\PressConfig;

/**
 * The framework-agnostic Cache API — one instance per request, shared as
 * $kernel->cache. Two jobs: (1) HTTP response caching, opt-in via
 * markPageCacheable() so pages with a per-session CSRF form never cache;
 * (2) purging an external reverse-proxy/edge cache (LiteSpeed today) on
 * content change. Lumora Press has no server-side HTML cache of its own —
 * it only emits headers and purges the external system that honors them.
 */
final class CacheManager
{
    private const PURGE_LOG_PATH_SUFFIX = '/storage/cache/cache-purge-log.json';

    private const PURGE_LOG_LIMIT = 20;

    private bool $pageCacheable = false;

    private bool $disabled = false;

    private ?int $lifetime = null;

    /** @var array<int, string> */
    private array $tags = [];

    public function __construct(
        private readonly PressConfig $config,
        private readonly CacheDriverInterface $driver,
        private readonly string $installRoot,
    ) {
    }

    /**
     * Opts the current response into HTTP caching, with cache tags
     * attached for later purgeTag() calls. Only call this for a response
     * safe to serve identically to every visitor — no embedded
     * per-session CSRF form, no user-specific content.
     *
     * @param array<int, string> $tags
     */
    public function markPageCacheable(array $tags = []): void
    {
        $this->pageCacheable = true;
        $this->registerTags($tags);
    }

    public function disableCaching(): void
    {
        $this->disabled = true;
    }

    /**
     * Overrides the configured default cache lifetime for this response
     * only (Core Cache API's setCacheLifetime()).
     */
    public function setCacheLifetime(int $seconds): void
    {
        $this->lifetime = max(0, $seconds);
    }

    public function registerTag(string $tag): void
    {
        $this->tags[] = $tag;
    }

    /**
     * @param array<int, string> $tags
     */
    public function registerTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->registerTag($tag);
        }
    }

    /**
     * Whether the current response should actually be sent with public
     * caching headers — requires both an explicit markPageCacheable()
     * call and the site-wide "cache_enabled" option, and is vetoed by
     * disableCaching() regardless of either (a plugin's escape hatch).
     */
    public function isCacheable(): bool
    {
        return $this->pageCacheable && !$this->disabled && $this->config->option('cache_enabled', '1') !== '0';
    }

    public function driverName(): string
    {
        return $this->driver->name();
    }

    public function driverAvailable(): bool
    {
        return $this->driver->isAvailable();
    }

    /**
     * Emits response headers and reports whether the body should still be
     * sent. Called once from index.php after output buffering, so $body
     * is the complete rendered page and a content-hash ETag can be
     * computed from it. A non-cacheable response always gets a safe
     * `Cache-Control: no-store, private`; only a cacheable one can end in
     * a 304.
     */
    public function applyHeaders(string $body): bool
    {
        if (!$this->isCacheable()) {
            // Some routes manage their own Cache-Control/ETag headers directly
            // and must not be overwritten here.
            if (!$this->hasSentHeader('Cache-Control')) {
                header('Cache-Control: no-store, private');
            }

            return true;
        }

        $lifetime = $this->lifetime ?? max(0, (int) $this->config->option('cache_default_lifetime', '3600'));
        $etag = '"' . md5($body) . '"';

        header('Cache-Control: public, max-age=' . $lifetime);
        header('ETag: ' . $etag);
        // Cookie-sensitive rather than Vary: * so shared guest responses stay
        // cacheable while logged-in visitors still get their own copy.
        header('Vary: Cookie');

        if ($this->tags !== [] && $this->driver->name() === 'litespeed') {
            header('X-LiteSpeed-Tag: ' . implode(',', array_unique($this->tags)));
            header('X-LiteSpeed-Cache-Control: public,max-age=' . $lifetime);
        }

        $ifNoneMatch = is_string($_SERVER['HTTP_IF_NONE_MATCH'] ?? null) ? trim($_SERVER['HTTP_IF_NONE_MATCH']) : null;

        if ($ifNoneMatch === $etag) {
            http_response_code(304);

            return false;
        }

        return true;
    }

    private function hasSentHeader(string $name): bool
    {
        foreach (headers_list() as $header) {
            if (stripos($header, $name . ':') === 0) {
                return true;
            }
        }

        return false;
    }

    public function purgeUrl(string $url): void
    {
        $this->driver->purgeUrl($url);
        $this->logPurge('url', $url);
    }

    /**
     * @param array<int, string> $urls
     */
    public function purgeUrls(array $urls): void
    {
        foreach ($urls as $url) {
            $this->purgeUrl($url);
        }
    }

    public function purgeTag(string $tag): void
    {
        $this->driver->purgeTag($tag);
        $this->logPurge('tag', $tag);
    }

    /**
     * @param array<int, string> $tags
     */
    public function purgeTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->purgeTag($tag);
        }
    }

    public function purgeAll(): void
    {
        $this->driver->purgeAll();
        $this->logPurge('all', '*');
    }

    /**
     * Recent purges, newest first, for the admin cache screen. Stored in a
     * plain JSON file rather than a PressConfig option — logging via
     * setOption() would fire 'option_changed', which triggers another
     * purge, which logs again, forever.
     *
     * @return array<int, array{type: string, value: string, at: string}>
     */
    public function recentPurges(): array
    {
        $path = $this->purgeLogPath();

        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function logPurge(string $type, string $value): void
    {
        $entries = $this->recentPurges();
        array_unshift($entries, ['type' => $type, 'value' => $value, 'at' => date('Y-m-d H:i:s')]);
        $entries = array_slice($entries, 0, self::PURGE_LOG_LIMIT);

        $directory = dirname($this->purgeLogPath());

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return;
        }

        file_put_contents($this->purgeLogPath(), json_encode($entries));
    }

    private function purgeLogPath(): string
    {
        return rtrim($this->installRoot, '/') . self::PURGE_LOG_PATH_SUFFIX;
    }
}
