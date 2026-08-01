<?php

declare(strict_types=1);

namespace LumoraPress\Core\Cache;

/**
 * A reverse-proxy/edge cache driver — the thing CacheManager delegates
 * actual purging to (LP-037). "Purge" here always means telling an
 * external cache to drop content; Lumora Press itself has no page-cache
 * storage of its own to purge, only the HTTP headers it emits and
 * whatever downstream system (LiteSpeed, a CDN, a reverse proxy) honors
 * them.
 */
interface CacheDriverInterface
{
    /**
     * A short, stable identifier — e.g. "null", "litespeed" — used for
     * admin display and by CacheManager to decide whether to emit
     * driver-specific headers (X-LiteSpeed-Tag, currently the only one).
     */
    public function name(): string;

    /**
     * Whether this driver's target cache system was actually detected on
     * this server. The Null driver is always available (it's the
     * do-nothing fallback); a real driver like LiteSpeed should only
     * report true when it can positively detect its own cache system,
     * never optimistically.
     */
    public function isAvailable(): bool;

    public function purgeUrl(string $url): void;

    public function purgeTag(string $tag): void;

    public function purgeAll(): void;
}
