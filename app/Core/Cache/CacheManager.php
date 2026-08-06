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
 * The framework-agnostic Cache API (LP-037) — one instance per request,
 * built in include/bootstrap.php and shared as $kernel->cache. Two
 * distinct jobs:
 *
 *  1. HTTP response caching for the current request. Opt-in, not
 *     detected automatically: a SiteController action calls
 *     markPageCacheable() only for routes it knows are safe to cache
 *     (see that method's docblock for why this matters — pages embedding
 *     a per-session CSRF-protected form, like a single post's comment
 *     form, must never opt in). index.php reads isCacheable()/
 *     applyHeaders() after rendering to decide what headers a guest GET
 *     request gets; every other request (POST, admin, logged-in, or a
 *     route that never opted in) gets a safe "don't cache this"
 *     response by default.
 *
 *  2. Purging an external reverse-proxy/edge cache (LiteSpeed today —
 *     see LiteSpeedCacheDriver) when content changes, via the
 *     'post_saved'/'page_saved'/.../'option_changed' listeners
 *     include/bootstrap.php registers after constructing this class.
 *
 * Deliberately does not track image views or maintain its own page-cache
 * storage — Lumora Press has no server-side HTML cache of its own; it
 * only emits headers for whatever reverse proxy/browser/CDN sits in
 * front of it, and purges that same external system on change.
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
     * Opts the current response into HTTP caching, with the given cache
     * tags attached (for later purgeTag() calls — e.g. a single
     * category archive registers 'category_5' alongside a blanket
     * 'posts' tag). Callers must only call this for a response that is
     * genuinely safe to serve to every visitor identically: no embedded
     * per-session CSRF form (a stale cached token can never validate for
     * a different visitor's session — see Csrf's docblock), no
     * user-specific content. SiteController::singlePost() deliberately
     * never calls this, since single.php renders a CSRF-protected
     * comment form.
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
     * Emits response headers for the current request and reports whether
     * the body should actually be sent — called once from index.php,
     * after output buffering has captured whatever the router produced,
     * so $body is the complete rendered page and a content-hash ETag can
     * be computed from it. Not cacheable (POST, admin, logged-in,
     * disabled, or a route that never opted in) always gets a safe
     * `Cache-Control: no-store, private` and the body is still sent —
     * only a cacheable response can ever end in a 304. No headers_sent()
     * guard: index.php always calls this only after buffering the full
     * response (see its LP-037 docblock), so headers are never actually
     * on the wire yet by this point in normal operation.
     */
    public function applyHeaders(string $body): bool
    {
        if (!$this->isCacheable()) {
            // Only when nothing else has already set one — a handful of
            // routes (SiteController::feed(), which predates this class)
            // manage their own Cache-Control/ETag headers directly and
            // must never have them overwritten here.
            if (!$this->hasSentHeader('Cache-Control')) {
                header('Cache-Control: no-store, private');
            }

            return true;
        }

        $lifetime = $this->lifetime ?? max(0, (int) $this->config->option('cache_default_lifetime', '3600'));
        $etag = '"' . md5($body) . '"';

        header('Cache-Control: public, max-age=' . $lifetime);
        header('ETag: ' . $etag);
        // Cookie-sensitive rather than a blanket Vary: * — a shared cache
        // still gets to reuse the response for every guest (no cookie or
        // an identical one), it just won't hand a guest-rendered copy to
        // a logged-in visitor whose session cookie differs.
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
     * Recent purges (LP-037's Admin Integration "Recent purge log"),
     * newest first. Written to a small JSON file under storage/cache/
     * rather than a PressConfig option deliberately: PressConfig::
     * setOption() fires the 'option_changed' hook this class's own
     * bootstrap.php listener uses to trigger purgeAll() on configuration
     * changes — logging a purge through setOption() would immediately
     * trigger another purge, which logs again, forever.
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
