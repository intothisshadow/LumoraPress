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
 * LiteSpeed/OpenLiteSpeed LSCache integration. Purging works entirely
 * through response headers — LiteSpeed watches every response for an
 * `X-LiteSpeed-Purge` header and acts on it, so nothing needs to be
 * queued or sent out-of-band.
 */
final class LiteSpeedCacheDriver implements CacheDriverInterface
{
    public function name(): string
    {
        return 'litespeed';
    }

    /**
     * True when the server identifies as LiteSpeed/OpenLiteSpeed or the
     * request carries the `X-LSCACHE` header. Deliberately permissive:
     * a false positive just sends purge headers LiteSpeed ignores, while
     * a false negative would silently drop real purges.
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
     * `replace: false` so multiple purges in the same request each add
     * their own `X-LiteSpeed-Purge` header line rather than overwriting
     * the previous one — LiteSpeed reads every occurrence.
     */
    private function sendPurgeHeader(string $value): void
    {
        header('X-LiteSpeed-Purge: ' . $value, false);
    }
}
