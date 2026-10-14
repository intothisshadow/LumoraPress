<?php

/**
 * Resolves the real visitor IP address and protocol when the site sits behind a reverse proxy.
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
 * Behind a proxy, REMOTE_ADDR is the proxy's address, so login throttling, comment rate
 * limits, and the maintenance IP allowlist would all key on one shared address. Forwarded
 * headers are client-controlled unless a trusted proxy set them, so they are honoured only
 * when the TCP peer itself is on the administrator's trusted list.
 */
final class TrustedProxies
{
    /**
     * Normalises the administrator's list: one IP or CIDR range per line (commas and spaces
     * also separate), `#` starts a comment, and anything that isn't a valid address is dropped.
     *
     * @return array<int, string>
     */
    public static function parse(string $raw): array
    {
        $entries = [];

        foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);

            foreach (preg_split('/[\s,]+/', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
                if (self::isValidEntry($entry) && !in_array($entry, $entries, true)) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    public static function isValidEntry(string $entry): bool
    {
        $address = $entry;
        $prefix = null;

        if (str_contains($entry, '/')) {
            [$address, $prefixText] = explode('/', $entry, 2);

            if ($prefixText === '' || !ctype_digit($prefixText)) {
                return false;
            }

            $prefix = (int) $prefixText;
        }

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $maxPrefix = str_contains($address, ':') ? 128 : 32;

        return $prefix === null || $prefix <= $maxPrefix;
    }

    /**
     * @param array<int, string> $ranges entries from parse()
     */
    public static function matches(string $ip, array $ranges): bool
    {
        $ip = self::normalizeIp($ip);

        foreach ($ranges as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns $server with REMOTE_ADDR replaced by the visitor's address and HTTPS set when
     * the proxy reports a secure connection. The proxy's own address is kept in
     * LP_PROXY_ADDR so the settings screen can show what was resolved.
     *
     * @param array<string, mixed> $server
     * @param array<int, string> $ranges entries from parse()
     * @return array<string, mixed>
     */
    public static function apply(array $server, array $ranges): array
    {
        if ($ranges === []) {
            return $server;
        }

        $peer = self::normalizeIp((string) ($server['REMOTE_ADDR'] ?? ''));

        if ($peer === '' || !self::matches($peer, $ranges)) {
            return $server;
        }

        $client = self::clientIp($server, $ranges);

        if ($client !== null) {
            $server['LP_PROXY_ADDR'] = $peer;
            $server['REMOTE_ADDR'] = $client;
        }

        if (self::forwardedAsHttps($server)) {
            $server['HTTPS'] = 'on';
        }

        return $server;
    }

    /**
     * @param array<string, mixed> $server
     * @param array<int, string> $ranges
     */
    private static function clientIp(array $server, array $ranges): ?string
    {
        // Walking X-Forwarded-For from the right and stopping at the first address that
        // isn't one of our proxies is the only reading a client can't forge, because every
        // trusted hop appends to the right and a spoofed value ends up further left.
        $forwarded = array_reverse(array_map('trim', explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''))));
        $candidate = null;

        foreach ($forwarded as $address) {
            $address = self::normalizeIp($address);

            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                break;
            }

            $candidate = $address;

            if (!self::matches($address, $ranges)) {
                return $address;
            }
        }

        if ($candidate !== null) {
            return $candidate;
        }

        // Fallbacks for proxies that don't send X-Forwarded-For.
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $header) {
            $address = self::normalizeIp(trim((string) ($server[$header] ?? '')));

            if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                return $address;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function forwardedAsHttps(array $server): bool
    {
        $protocol = strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));

        if ($protocol === 'https') {
            return true;
        }

        if (strtolower((string) ($server['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on' || strtolower((string) ($server['HTTP_FRONT_END_HTTPS'] ?? '')) === 'on') {
            return true;
        }

        return str_contains(strtolower((string) ($server['HTTP_CF_VISITOR'] ?? '')), '"scheme":"https"');
    }

    /**
     * An IPv4 client reaching a dual-stack socket arrives as ::ffff:a.b.c.d, which would
     * otherwise never match an IPv4 range.
     */
    private static function normalizeIp(string $ip): string
    {
        if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return substr($ip, 7);
        }

        return $ip;
    }

    private static function inRange(string $ip, string $range): bool
    {
        $prefix = null;
        $network = $range;

        if (str_contains($range, '/')) {
            [$network, $prefixText] = explode('/', $range, 2);
            $prefix = (int) $prefixText;
        }

        $network = self::normalizeIp($network);

        // inet_pton() warns on malformed input, so validate before converting.
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || filter_var($network, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $ipBytes = inet_pton($ip);
        $networkBytes = inet_pton($network);

        if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes)) {
            return false;
        }

        $bits = $prefix ?? strlen($ipBytes) * 8;

        if ($bits < 0 || $bits > strlen($ipBytes) * 8) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);

        if ($wholeBytes > 0 && substr($ipBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) {
            return false;
        }

        $remainder = $bits % 8;

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ipBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
    }
}
