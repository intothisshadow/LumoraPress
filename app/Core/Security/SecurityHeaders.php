<?php

/**
 * The browser security headers sent on every response, next to the Content-Security-Policy.
 *
 * @package LumoraPress
 * @subpackage Security
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Security;

/**
 * Everything here is sent unconditionally except HSTS, because nothing legitimate is lost by the
 * rest. HSTS is the one real trade-off: once a browser has seen it, it refuses plain HTTP for
 * the site for a year, so a site whose HTTPS later breaks becomes unreachable. It is therefore an
 * opt-in, and only ever sent over HTTPS (a browser ignores it over HTTP anyway). Sent whether or
 * not the Content-Security-Policy is enabled, since X-Frame-Options and nosniff do not depend on it.
 */
final class SecurityHeaders
{
    /** A year, the usual value and the minimum browsers need for HSTS preload lists. */
    public const HSTS_MAX_AGE_SECONDS = 31536000;

    /**
     * @return array<string, string> header name => value
     */
    public static function headers(bool $isHttps, bool $hstsEnabled): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];

        if ($isHttps && $hstsEnabled) {
            $headers['Strict-Transport-Security'] = 'max-age=' . self::HSTS_MAX_AGE_SECONDS;
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     */
    public static function send(array $headers): void
    {
        if (headers_sent()) {
            return;
        }

        foreach ($headers as $name => $value) {
            if ($value !== '') {
                header($name . ': ' . $value);
            }
        }
    }
}
