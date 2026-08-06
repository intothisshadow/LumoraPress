<?php

/**
 * LiteSpeed/OpenLiteSpeed LSCache integration (LP-037).
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

/**
 * LiteSpeed/OpenLiteSpeed LSCache integration (LP-037). Purging works
 * entirely through response headers — LiteSpeed watches every response
 * (not just the page being purged) for an `X-LiteSpeed-Purge` header and
 * acts on it, so a purge triggered by, say, saving a post in the admin
 * panel takes effect via the header on *that* admin response, with
 * nothing to queue or send out-of-band. See CacheManager for where these
 * calls actually happen during a request.
 */
final class LiteSpeedCacheDriver implements CacheDriverInterface
{
    public function name(): string
    {
        return 'litespeed';
    }

    /**
     * True whenever the web server identifies itself as LiteSpeed/
     * OpenLiteSpeed (`SERVER_SOFTWARE`) or the request carries the
     * `X-LSCACHE` header LiteSpeed's own cache module adds — the latter
     * is the stronger signal (confirms the cache module itself is
     * active, not just the web server), so either is treated as
     * "detected" for admin display purposes. Sending purge headers when
     * only the web server (not the cache module) is present is harmless
     * — LiteSpeed simply ignores them — so this stays permissive rather
     * than risking a false negative that silently drops real purges.
     */
    public function isAvailable(): bool
    {
        $serverSoftware = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');

        return str_contains(strtolower($serverSoftware), 'litespeed')
            || isset($_SERVER['HTTP_X_LSCACHE']);
    }

    public function purgeUrl(string $url): void
    {
        $this->sendPurgeHeader($url);
    }

    public function purgeTag(string $tag): void
    {
        $this->sendPurgeHeader('tag=' . $tag);
    }

    public function purgeAll(): void
    {
        $this->sendPurgeHeader('*');
    }

    /**
     * `replace: false` so multiple purges within the same request each
     * add their own `X-LiteSpeed-Purge` header line rather than
     * overwriting the previous one — LiteSpeed reads every occurrence. No
     * headers_sent() guard: by the time anything triggers a purge
     * (content saved, a setting changed), the response that purge fires
     * on is still being buffered (see index.php's LP-037 output-buffer
     * wrapping, and admin/index.php's own pre-existing one), so headers
     * are never actually sent yet in practice — and if some future
     * caller ever did purge after real output had started, PHP's own
     * "headers already sent" warning is more useful than a silent no-op
     * (see CLAUDE.md: never suppress errors).
     */
    private function sendPurgeHeader(string $value): void
    {
        header('X-LiteSpeed-Purge: ' . $value, false);
    }
}
