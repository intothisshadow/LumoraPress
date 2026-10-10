<?php

/**
 * Matches a request path against the administrator's "never cache" list.
 *
 * @package LumoraPress
 * @subpackage Cache
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.23.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Cache;

/**
 * One pattern per line, matched against the URL path only (never the query
 * string). `*` matches any run of characters including `/`; everything else
 * is literal, so a pasted path can't be misread as a regular expression.
 */
final class CacheExclusions
{
    /**
     * @return list<string>
     */
    public static function parse(string $patterns): array
    {
        $parsed = [];

        foreach (preg_split('/\R/', $patterns) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parsed[] = '/' . ltrim($line, '/');
        }

        return $parsed;
    }

    public static function matches(string $path, string $patterns): bool
    {
        $path = self::normalizePath($path);

        foreach (self::parse($patterns) as $pattern) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote(self::normalizePath($pattern), '#')) . '$#';

            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    // `/about` and `/about/` are the same page to the router.
    private static function normalizePath(string $path): string
    {
        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
