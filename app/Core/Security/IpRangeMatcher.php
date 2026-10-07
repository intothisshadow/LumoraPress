<?php

/**
 * Matches an IP address against a list of single addresses and CIDR ranges.
 *
 * @package LumoraPress
 * @subpackage Security
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.21.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Security;

/**
 * Works on the packed binary form from inet_pton() so IPv4 and IPv6 share
 * one comparison, and so an IPv4 address can never match an IPv6 rule (or
 * the reverse) by accident. A malformed rule is skipped rather than
 * treated as a match: an allow-list typo must never open anything up.
 */
final class IpRangeMatcher
{
    /**
     * @param list<string> $rules single addresses ("203.0.113.5", "2001:db8::1") or CIDR ranges ("203.0.113.0/24")
     */
    public static function matches(string $ipAddress, array $rules): bool
    {
        $ip = inet_pton($ipAddress);

        if ($ip === false) {
            return false;
        }

        foreach ($rules as $rule) {
            if (self::ruleMatches($ip, trim($rule))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Splits a textarea value into rules, one per line, ignoring blank
     * lines and "#" comments.
     *
     * @return list<string>
     */
    public static function parseList(string $raw): array
    {
        $rules = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);

            if ($line !== '') {
                $rules[] = $line;
            }
        }

        return $rules;
    }

    public static function isValidRule(string $rule): bool
    {
        $rule = trim($rule);
        [$address, $prefix] = array_pad(explode('/', $rule, 2), 2, null);
        $packed = inet_pton($address);

        if ($packed === false) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        return ctype_digit($prefix) && (int) $prefix <= strlen($packed) * 8;
    }

    private static function ruleMatches(string $ip, string $rule): bool
    {
        if (!self::isValidRule($rule)) {
            return false;
        }

        [$address, $prefix] = array_pad(explode('/', $rule, 2), 2, null);
        $network = inet_pton($address);

        if ($network === false || strlen($network) !== strlen($ip)) {
            return false;
        }

        $bits = $prefix === null ? strlen($network) * 8 : (int) $prefix;
        $fullBytes = intdiv($bits, 8);

        if (substr($ip, 0, $fullBytes) !== substr($network, 0, $fullBytes)) {
            return false;
        }

        $remainder = $bits % 8;

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ip[$fullBytes]) & $mask) === (ord($network[$fullBytes]) & $mask);
    }
}
