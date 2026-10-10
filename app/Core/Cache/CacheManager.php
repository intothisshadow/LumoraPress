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

use LumoraPress\Core\Http\BasePath;
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

    /**
     * Public page kinds the administrator can switch caching on or off for
     * individually (option `cache_type_{key}`, on unless set to '0').
     */
    public const CONTENT_TYPES = [
        'home' => 'Homepage and posts listing',
        'pages' => 'Pages',
        'categories' => 'Category archives',
        'tags' => 'Tag archives',
        'archives' => 'Date and author archives',
        'search' => 'Search results',
    ];

    private bool $pageCacheable = false;

    private bool $disabled = false;

    private ?int $lifetime = null;

    /** @var array<int, string> */
    private array $tags = [];

    /**
     * @param array<string, CacheDriverInterface> $registeredDrivers every driver selectable on Settings > Cache, keyed by slug
     */
    public function __construct(
        private readonly PressConfig $config,
        private readonly CacheDriverInterface $driver,
        private readonly string $installRoot,
        private readonly array $registeredDrivers = [],
    ) {
    }

    /**
     * Opts the current response into HTTP caching, with cache tags
     * attached for later purgeTag() calls. Only call this for a response
     * safe to serve identically to every visitor — no embedded
     * per-session CSRF form, no user-specific content. A $contentType from
     * CONTENT_TYPES lets the administrator switch that kind of page off;
     * an unknown type is always allowed.
     *
     * @param array<int, string> $tags
     */
    public function markPageCacheable(array $tags = [], string $contentType = 'other'): void
    {
        if (isset(self::CONTENT_TYPES[$contentType]) && $this->config->option('cache_type_' . $contentType, '1') === '0') {
            return;
        }

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
        return $this->pageCacheable
            && !$this->disabled
            && $this->config->option('cache_enabled', '1') !== '0'
            && !$this->requestPathExcluded();
    }

    private function requestPathExcluded(): bool
    {
        $patterns = (string) $this->config->option('cache_exclusions', '');

        if ($patterns === '') {
            return false;
        }

        // Patterns are written relative to the site, so a subdirectory
        // install's base path is removed first, as the router does.
        $path = (string) (parse_url(BasePath::stripFrom((string) ($_SERVER['REQUEST_URI'] ?? '/')), PHP_URL_PATH) ?? '/');

        return CacheExclusions::matches($path, $patterns);
    }

    public function driverName(): string
    {
        return $this->driver->name();
    }

    /**
     * Slugs of every driver selectable on Settings > Cache, built-in and
     * plugin-registered, so the admin select never has to hardcode them.
     *
     * @return array<int, string>
     */
    public function registeredDriverSlugs(): array
    {
        return array_keys($this->registeredDrivers);
    }

    /**
     * Plain-language problems with the current cache settings, for the
     * admin screen. Advisory only; nothing here blocks saving.
     *
     * @return array<int, string>
     */
    public function configurationWarnings(string $serverSoftware): array
    {
        $warnings = [];
        $enabled = $this->config->option('cache_enabled', '1') !== '0';
        $override = (string) $this->config->option('cache_driver', 'auto');
        $serverIsLiteSpeed = str_contains(strtolower($serverSoftware), 'litespeed');

        if ($enabled && (int) $this->config->option('cache_default_lifetime', '3600') === 0) {
            $warnings[] = 'The default cache lifetime is 0, so cacheable pages are marked as already stale and nothing is actually reused. Set a lifetime above 0 or turn HTTP caching off.';
        }

        $browserLifetime = trim((string) $this->config->option('cache_browser_lifetime', ''));

        if ($enabled && $browserLifetime !== '' && (int) $browserLifetime > (int) $this->config->option('cache_default_lifetime', '3600')) {
            $warnings[] = 'The browser cache lifetime is longer than the default cache lifetime. After a purge, visitors\' browsers will keep showing the old page until their own lifetime ends. Keep the browser lifetime equal to or shorter than the default.';
        }

        if ($override === 'litespeed' && !$serverIsLiteSpeed) {
            $warnings[] = 'The cache driver is forced to LiteSpeed, but this server does not identify as LiteSpeed. Purge requests will be sent and then ignored. Switch the driver to automatic detection unless a LiteSpeed proxy sits in front of this site.';
        }

        if (!$enabled && $this->driver->name() !== 'null') {
            $warnings[] = 'HTTP caching is turned off while a ' . $this->driver->name() . ' cache is active. Pages are sent as uncacheable, but the cache is still purged on every content change.';
        }

        if ($enabled) {
            $anyTypeCached = false;

            foreach (array_keys(self::CONTENT_TYPES) as $type) {
                $anyTypeCached = $anyTypeCached || $this->config->option('cache_type_' . $type, '1') !== '0';
            }

            if (!$anyTypeCached) {
                $warnings[] = 'HTTP caching is on, but every kind of page is switched off under "What gets cached", so nothing will be cached.';
            }
        }

        if ($override !== 'auto' && !isset($this->registeredDrivers[$override])) {
            $warnings[] = 'The selected cache driver "' . $override . '" is not available (a plugin that provided it may have been deactivated). The None driver is being used instead.';
        }

        return $warnings;
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
        $etag = self::etagFor($body);

        $browserLifetime = $this->browserLifetime($lifetime);

        // s-maxage only when the browser's lifetime differs, so the common
        // single-lifetime setup keeps its plain header.
        header('Cache-Control: public, max-age=' . $browserLifetime . ($browserLifetime !== $lifetime ? ', s-maxage=' . $lifetime : ''));
        header('ETag: ' . $etag);
        // Cookie-sensitive rather than Vary: * so shared guest responses stay
        // cacheable while logged-in visitors still get their own copy.
        header('Vary: Cookie');

        if ($this->tags !== [] && $this->driver->name() === 'litespeed') {
            header('X-LiteSpeed-Tag: ' . implode(',', array_unique($this->tags)));
            header('X-LiteSpeed-Cache-Control: public,max-age=' . $lifetime);
        }

        // LiteSpeed removes its own tag header before the response leaves the
        // server, so tag debugging needs a copy under a name it leaves alone.
        if ($this->tags !== [] && $this->config->option('cache_debug_tags', '0') === '1') {
            header('X-Lumora-Cache-Tags: ' . implode(',', array_unique($this->tags)));
        }

        $ifNoneMatch = is_string($_SERVER['HTTP_IF_NONE_MATCH'] ?? null) ? $_SERVER['HTTP_IF_NONE_MATCH'] : '';

        if (self::ifNoneMatchMatches($ifNoneMatch, $etag)) {
            // A browser applies a 304's headers to the copy it already holds.
            // This request's fresh CSP nonce would not match the nonce in
            // that stored body, so the stored policy must stay in force.
            header_remove('Content-Security-Policy');
            header_remove('Content-Security-Policy-Report-Only');
            http_response_code(304);

            return false;
        }

        return true;
    }

    /**
     * Content-hash ETag that ignores CSP nonces. Every response carries a
     * fresh per-request nonce in its inline tags, so hashing the raw body
     * would give a different ETag each time and no 304 could ever match.
     */
    public static function etagFor(string $body): string
    {
        return '"' . md5(preg_replace('/\snonce="[^"]*"/', '', $body) ?? $body) . '"';
    }

    /**
     * Whether any validator in an If-None-Match header names $etag. Weak
     * markers and the content-coding suffix Apache's mod_deflate (or
     * nginx/brotli modules) appends to the ETag it sends on compressed
     * responses ("abc-gzip") are ignored, otherwise the browser's
     * revalidation never matches on a compressing host and a 304 is
     * never answered.
     */
    public static function ifNoneMatchMatches(string $header, string $etag): bool
    {
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*') {
                return true;
            }

            $candidate = preg_replace('/^W\//', '', $candidate) ?? $candidate;
            $candidate = preg_replace('/-(?:gzip|br|deflate|zstd)"$/', '"', $candidate) ?? $candidate;

            if ($candidate !== '' && $candidate === $etag) {
                return true;
            }
        }

        return false;
    }

    /**
     * How long a visitor's browser may reuse a page. Blank means "same as
     * the server lifetime", so a shorter browser value lets the server-side
     * cache stay long while visitors still pick up a purge quickly.
     */
    private function browserLifetime(int $serverLifetime): int
    {
        $configured = trim((string) $this->config->option('cache_browser_lifetime', ''));

        return $configured === '' ? $serverLifetime : max(0, (int) $configured);
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

    /**
     * Options the application writes about its own housekeeping (index
     * refresh times, update checks, the install ping). Changing one never
     * changes what a visitor sees, so it must not empty the page cache.
     */
    public static function isBookkeepingOption(string $key): bool
    {
        foreach (['search_index_', 'search_vocabulary_', 'update_last_', 'install_ping_'] as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return $key === 'install_uuid';
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
