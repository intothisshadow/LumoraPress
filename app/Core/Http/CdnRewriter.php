<?php

/**
 * Rewrites theme and core static-asset URLs to a configured CDN host.
 *
 * @package LumoraPress
 * @subpackage Http
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Http;

/**
 * Only host-relative URLs are rewritten, so an absolute URL a theme author wrote on purpose
 * is never touched. The CDN is expected to be a pull zone mirroring this site's paths.
 */
final class CdnRewriter
{
    private static string $origin = '';

    /**
     * Reduces the administrator's input to a bare `https://host` origin. Returns null for
     * anything else (a path, a query, a non-http scheme, credentials) so a typo can't turn
     * every asset URL into something broken.
     */
    public static function normalize(string $raw): ?string
    {
        $raw = rtrim(trim($raw), '/');
        $parts = parse_url($raw);

        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['path']) && $parts['path'] !== '')) {
            return null;
        }

        if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $parts['host']) !== 1) {
            return null;
        }

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    public static function set(string $origin): void
    {
        self::$origin = self::normalize($origin) ?? '';
    }

    public static function origin(): string
    {
        return self::$origin;
    }

    public static function rewrite(string $url): string
    {
        if (self::$origin === '' || $url === '' || $url[0] !== '/' || str_starts_with($url, '//')) {
            return $url;
        }

        return self::$origin . $url;
    }
}
