<?php

/**
 * The default/fallback cache driver (LP-037): a safe no-op used whenever no real caching layer is detected.
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
 * Default/fallback driver (LP-037's "Initial drivers: Null driver
 * (default/no caching)") — used whenever no supported reverse-proxy/edge
 * cache is detected. Every purge call is a deliberate no-op: with no
 * external cache to tell, there's nothing to do, but CacheManager can
 * still call purgeUrl()/purgeTag()/purgeAll() unconditionally without
 * checking which driver is active first.
 */
final class NullCacheDriver implements CacheDriverInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function purgeUrl(string $url): void
    {
    }

    public function purgeTag(string $tag): void
    {
    }

    public function purgeAll(): void
    {
    }
}
