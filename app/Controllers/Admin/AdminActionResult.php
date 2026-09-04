<?php

/**
 * Return value for an admin controller action method: either a redirect target or an inline error, never both.
 *
 * @package LumoraPress
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Controllers\Admin;

/**
 * Deliberately returned rather than acted on directly — an admin
 * controller action method never calls header()/exit itself, so a plain
 * PHPUnit test can assert on the outcome without needing to intercept a
 * real exit() call. The thin admin view is responsible for turning a
 * non-null redirectUrl into an actual redirect() call once control
 * returns to it.
 */
final readonly class AdminActionResult
{
    private function __construct(
        public ?string $redirectUrl,
        public ?string $errorMessage,
    ) {
    }

    public static function redirect(string $url): self
    {
        return new self($url, null);
    }

    public static function error(string $message): self
    {
        return new self(null, $message);
    }
}
