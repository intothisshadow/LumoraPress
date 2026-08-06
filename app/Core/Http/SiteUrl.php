<?php

/**
 * Static bridge exposing the site's absolute URL (scheme, host, and base path).
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
 * Static bridge exposing the site's absolute URL (scheme + host + base
 * path, e.g. "https://example.com" or "https://example.com/lumorapress")
 * to the procedural home_url() helper used by core, themes, and plugins.
 * Mirrors BasePath, which exposes the path-only portion.
 */
final class SiteUrl
{
    private static string $value = '';

    public static function set(string $url): void
    {
        self::$value = rtrim($url, '/');
    }

    public static function get(): string
    {
        return self::$value;
    }

    /**
     * Detects the site's absolute URL from the current request. $basePath
     * should already be in BasePath's normalized form (empty for a
     * domain-root install, e.g. "/lumorapress" for a subdirectory install)
     * — the caller reads $_SERVER itself so this stays a pure, easily
     * testable function rather than reaching into superglobals directly.
     */
    public static function detect(bool $isHttps, string $host, string $basePath = ''): string
    {
        $scheme = $isHttps ? 'https' : 'http';

        return rtrim($scheme . '://' . $host . $basePath, '/');
    }
}
