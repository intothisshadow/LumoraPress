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

use DOMDocument;
use LumoraPress\Core\PressConfig;

/**
 * Shared by the Settings > Security > Trusted Image Sources save handler
 * and the `csp_directives` listener that builds the real header from it —
 * one validation rule in one place instead of a duplicated regex.
 *
 * Also owns the auto-trust behavior (autoTrustFromContent()): an
 * Administrator or Editor embedding an external image gets it trusted
 * automatically. Contributor/Author content is never auto-trusted — see
 * call sites for the capability gate.
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

    /**
     * Scans $html's `<img src>` attributes and returns the distinct
     * external origins found. $siteOrigin is excluded (already covered by
     * CSP's own 'self'). Malformed/relative/non-http(s) src is skipped.
     *
     * @return array<int, string>
     */
    public static function extractExternalOrigins(string $html, string $siteOrigin): array
    {
        if (!str_contains($html, '<img')) {
            return [];
        }

        $dom = new DOMDocument();
        $previousInternalErrors = libxml_use_internal_errors(true);

        // Same LIBXML_HTML_NOIMPLIED/_NODEFDTD + mb_convert_encoding()
        // fragment-parsing idiom as the WordPress Importer's
        // ContentImageRewriter — real post/page content is a bare
        // fragment, not a full document.
        $loaded = @$dom->loadHTML(
            mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previousInternalErrors);

        if (!$loaded) {
            return [];
        }

        $origins = [];

        foreach (iterator_to_array($dom->getElementsByTagName('img')) as $image) {
            $src = trim($image->getAttribute('src'));

            if ($src === '' || preg_match('#^https?://#i', $src) !== 1) {
                continue;
            }

            $parts = parse_url($src);

            if (!isset($parts['scheme'], $parts['host'])) {
                continue;
            }

            $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
                . (isset($parts['port']) ? ':' . $parts['port'] : '');

            if ($origin === $siteOrigin) {
                continue;
            }

            $origins[] = $origin;
        }

        return array_values(array_unique($origins));
    }

    /**
     * Adds any new external image origins found in $html to the site-wide
     * trusted_image_origins option. A no-op when nothing new is found.
     * Callers must gate this on the author being trusted staff
     * (`$currentUser->can('edit_others_posts')`) — this method has no way
     * to know who authored $html, by design.
     */
    public static function autoTrustFromContent(PressConfig $config, string $html, string $siteOrigin): void
    {
        $found = self::extractExternalOrigins($html, $siteOrigin);

        if ($found === []) {
            return;
        }

        $existing = self::parse((string) $config->option('trusted_image_origins', ''));
        $merged = array_values(array_unique([...$existing, ...$found]));

        if ($merged === $existing) {
            return;
        }

        sort($merged);
        $config->setOption('trusted_image_origins', implode("\n", $merged));
    }
}
