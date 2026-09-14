<?php

/**
 * IP address anonymization for privacy-conscious comment storage.
 *
 * @package LumoraPress
 * @subpackage Security
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.15.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Security;

/**
 * Masks the host-identifying portion of an IP address while leaving enough
 * of it intact for flood control and IP-blacklist matching to keep working
 * on the masked form. Follows the same convention analytics tools like
 * Google Analytics/Matomo use: the last IPv4 octet, or the last 80 bits of
 * an IPv6 address, are zeroed rather than the address being discarded
 * entirely.
 */
final class IpAnonymizer
{
    public static function anonymize(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);

            if (count($parts) === 4) {
                $parts[3] = '0';

                return implode('.', $parts);
            }

            return $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = @inet_pton($ip);

            if ($packed === false || strlen($packed) !== 16) {
                return $ip;
            }

            // Keep the first 48 bits (network/subnet) and zero the
            // remaining 80 bits (interface identifier).
            $masked = substr($packed, 0, 6) . str_repeat("\0", 10);
            $result = @inet_ntop($masked);

            return $result !== false ? $result : $ip;
        }

        return $ip;
    }
}
