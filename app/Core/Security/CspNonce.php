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
 * Static bridge exposing this request's CSP nonce to theme templates via
 * csp_style_nonce() (include/helpers.php), the "set once in bootstrap,
 * read via a static class" pattern also used by SiteBranding/ActiveTheme.
 *
 * A fresh value per request is added to style-src as 'nonce-{value}', which
 * is what lets core's inline <style> blocks run under CSP's default
 * `style-src 'self'`. Any <style> tag missing `nonce="<?= csp_style_nonce() ?>"`
 * is silently dropped by the browser with no console or PHP error.
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
