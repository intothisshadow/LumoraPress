<?php

/**
 * Coarse browser/device classification from a User-Agent string (LPP-014).
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\VisitorStats;

/**
 * Stateless, local, dependency-free pattern matching — no third-party
 * UA-parsing service, and callers only ever get back the two resolved
 * buckets below, never the raw string (see this plugin's Privacy
 * commitment in README.md).
 */
final class UserAgentParser
{
    /**
     * Chrome/Edge/most other Chromium browsers' UA strings also contain
     * "Safari" (a legacy compatibility token), so Safari must be checked
     * last — a common parsing mistake this order deliberately avoids.
     */
    public function browser(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Other';
        }

        if (str_contains($userAgent, 'Edg/') || str_contains($userAgent, 'Edge/')) {
            return 'Edge';
        }

        if (str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera')) {
            return 'Other';
        }

        if (str_contains($userAgent, 'Firefox/')) {
            return 'Firefox';
        }

        if (str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/')) {
            return 'Chrome';
        }

        if (str_contains($userAgent, 'Safari/') && str_contains($userAgent, 'Version/')) {
            return 'Safari';
        }

        return 'Other';
    }

    /**
     * Checks for a bot/crawler signature first so a crawler's UA (which
     * often also matches "Mobile" or a real OS token) never gets
     * miscounted as a real visitor's device.
     */
    public function deviceType(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Bot/Unknown';
        }

        if (preg_match('/bot|crawl|spider|slurp|facebookexternalhit|bingpreview/i', $userAgent) === 1) {
            return 'Bot/Unknown';
        }

        if (str_contains($userAgent, 'iPad') || (str_contains($userAgent, 'Android') && !str_contains($userAgent, 'Mobile'))) {
            return 'Tablet';
        }

        if (str_contains($userAgent, 'Mobi') || str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'Android')) {
            return 'Mobile';
        }

        return 'Desktop';
    }
}
