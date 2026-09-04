<?php

/**
 * Builds and sends the Content-Security-Policy header from a set of directives.
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

/**
 * Builds and sends a Content-Security-Policy header.
 *
 * Ships with a strict, same-origin-only default since core needs no
 * inline scripts/styles or third-party origins. A theme/plugin that
 * needs to loosen a directive should use the `csp_directives` filter
 * (applied in `include/bootstrap.php`) rather than editing this class —
 * it stays a pure header builder with no hook/config/database
 * dependency, so it's usable from `install/index.php` too.
 */
final class ContentSecurityPolicy
{
    /**
     * @return array<string, string>
     */
    public static function defaultDirectives(): array
    {
        return [
            'default-src' => "'self'",
            'base-uri' => "'self'",
            'form-action' => "'self'",
            'frame-ancestors' => "'self'",
            'object-src' => "'none'",
            'script-src' => "'self'",
            'style-src' => "'self'",
            'img-src' => "'self'",
            'font-src' => "'self'",
            'connect-src' => "'self'",
        ];
    }

    /**
     * @param array<string, string> $directives Directive name => value.
     *                                           Defaults to defaultDirectives() when empty.
     */
    public function __construct(private array $directives = [])
    {
        if ($this->directives === []) {
            $this->directives = self::defaultDirectives();
        }
    }

    public function header(): string
    {
        $parts = [];

        foreach ($this->directives as $name => $value) {
            $parts[] = $name . ' ' . $value;
        }

        return implode('; ', $parts);
    }

    /**
     * Sends the policy as a response header. A no-op if headers have
     * already been sent (e.g. output already started) rather than
     * throwing — a missing CSP header is far less harmful than a fatal
     * error on every page.
     */
    public function send(bool $reportOnly = false): void
    {
        if (headers_sent()) {
            return;
        }

        $headerName = $reportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';

        header($headerName . ': ' . $this->header());
    }
}
