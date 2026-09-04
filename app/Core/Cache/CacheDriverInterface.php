<?php

/**
 * The interface a reverse-proxy/edge cache driver implements so CacheManager can delegate purging to it (LP-037).
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
 * A reverse-proxy/edge cache driver that CacheManager delegates purging
 * to. "Purge" always means telling an external cache to drop content —
 * Lumora Press has no page-cache storage of its own.
 */
interface CacheDriverInterface
{
    /**
     * A short, stable identifier (e.g. "null", "litespeed") used for admin
     * display and to decide whether to emit driver-specific headers.
     */
    public function name(): string;

    /**
     * Whether this driver's target cache system was actually detected —
     * a real driver should report true only when it can positively
     * confirm its own cache system, never optimistically.
     */
    public function isAvailable(): bool;

    public function purgeUrl(string $url): void;

    public function purgeTag(string $tag): void;

    public function purgeAll(): void;
}
