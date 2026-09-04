<?php

/**
 * Static bridge exposing the site's install-time base path (empty for a domain-root install, a subdirectory otherwise).
 *
 * @package LumoraPress
 * @subpackage Http
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Http;

/**
 * Static bridge exposing the site's install-time base path (empty string
 * for a domain-root install, e.g. "/lumorapress" for a subdirectory
 * install) to the procedural site_url()/admin_url() helpers used by core,
 * themes, and plugins.
 */
final class BasePath
{
    private static string $value = '';

    public static function set(string $basePath): void
    {
        self::$value = rtrim($basePath, '/');
    }

    public static function get(): string
    {
        return self::$value;
    }

    /**
     * Strips the install's base path from a raw request URI (e.g.
     * "/lumorapress/admin/?x=1" -> "/admin/?x=1") so Router::dispatch()
     * can match its base-path-free route patterns. No-op for a
     * domain-root install, or if the URI doesn't start with the base path.
     */
    public static function stripFrom(string $uri): string
    {
        if (self::$value === '' || !str_starts_with($uri, self::$value)) {
            return $uri;
        }

        $stripped = substr($uri, strlen(self::$value));

        return $stripped === '' ? '/' : $stripped;
    }
}
