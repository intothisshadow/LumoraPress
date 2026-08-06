<?php

/**
 * Static bridge exposing the current request's Content-Security-Policy nonce to theme templates.
 *
 * @package LumoraPress
 * @subpackage Security
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Security;

use RuntimeException;

/**
 * Static bridge exposing this request's Content-Security-Policy nonce to
 * theme templates via the csp_style_nonce() helper (include/helpers.php),
 * the same "set once in bootstrap, read via a static class" pattern
 * SiteBranding/ActiveTheme/ThemeOptionsBridge already use.
 *
 * A fresh random value per request, added to the style-src directive as
 * 'nonce-{value}' (include/bootstrap.php's existing csp_directives
 * filter) — this is what lets the default theme's own inline <style>
 * blocks (Custom CSS, Theme Options CSS) execute under
 * ContentSecurityPolicy's default `style-src 'self'`, which otherwise
 * blocks every inline <style> tag outright. Every <style> tag emitted by
 * core must carry `nonce="<?= csp_style_nonce() ?>"` or it silently does
 * nothing in the browser — no console error most developers would think
 * to check, no PHP error either, since CSP enforcement happens entirely
 * client-side after a perfectly valid response was already sent.
 */
final class CspNonce
{
    private static ?string $value = null;

    public static function set(string $value): void
    {
        self::$value = $value;
    }

    public static function value(): string
    {
        if (self::$value === null) {
            throw new RuntimeException('CSP nonce has not been initialized.');
        }

        return self::$value;
    }
}
