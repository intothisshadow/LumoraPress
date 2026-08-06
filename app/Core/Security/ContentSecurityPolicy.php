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
 * Ships with a strict, same-origin-only default: nothing in Lumora Press's
 * core (front end, admin, or installer) currently needs inline scripts,
 * inline styles, or any third-party origin, so there is no reason to allow
 * them by default. Themes and plugins that genuinely need to loosen a
 * directive (e.g. a widget embedding video from an external host) should
 * do so via the `csp_directives` filter applied at the call site
 * (`include/bootstrap.php`) rather than by editing this class — the class
 * itself stays a pure, unopinionated header builder with no knowledge of
 * the hook system, config, or database, so it stays trivially testable and
 * reusable from `install/index.php`, which runs before either exists.
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
