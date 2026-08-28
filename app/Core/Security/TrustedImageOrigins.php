<?php

/**
 * Parses and validates the admin-configured list of external image origins allowed through the img-src CSP directive.
 *
 * @package LumoraPress
 * @subpackage Security
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Security;

/**
 * Shared by both the Settings > Security > Trusted Image Sources save
 * handler (so what's redisplayed on that screen always matches what's
 * actually allowed) and the `csp_directives` listener that builds the
 * real header from it (`include/bootstrap.php`) — one validation rule
 * kept in exactly one place, rather than the same regex duplicated at
 * both call sites and liable to drift apart.
 */
final class TrustedImageOrigins
{
    /**
     * Strict scheme://host[:port]-only — no path, query, or trailing
     * slash — so a malformed or malicious entry can never inject extra
     * CSP syntax (another directive, a wildcard, an unquoted keyword)
     * into the header via string concatenation.
     *
     * @return array<int, string>
     */
    public static function parse(string $rawOptionValue): array
    {
        $lines = array_filter(array_map('trim', explode("\n", $rawOptionValue)));

        return array_values(array_filter(
            $lines,
            static fn (string $origin): bool => preg_match('#^https?://[a-zA-Z0-9.-]+(?::\d+)?$#', $origin) === 1,
        ));
    }
}
