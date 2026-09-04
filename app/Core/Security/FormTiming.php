<?php

/**
 * Submission-timing spam check (LP-025): a form submitted implausibly fast is treated as automated.
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
 * A real visitor takes at least a few seconds to fill in a form, so an
 * instant submission is a strong bot signal — complementary to the
 * honeypot field used alongside it.
 *
 * Signed with the install's `secret_key` rather than stored in the session
 * like `Csrf`, so the timestamp stays stateless across checks. The HMAC
 * only proves the timestamp wasn't tampered with; `Csrf`'s token is still
 * what proves the request itself is genuine.
 */
final class FormTiming
{
    private static ?string $secretKey = null;

    public static function setSecretKey(string $key): void
    {
        self::$secretKey = $key;
    }

    public static function field(): string
    {
        $time = (string) time();

        return '<input type="hidden" name="form_time" value="' . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="form_time_hmac" value="' . htmlspecialchars(self::sign($time), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function verify(?string $time, ?string $hmac, int $minSeconds = 3): bool
    {
        if ($time === null || $hmac === null || !ctype_digit($time)) {
            return false;
        }

        if (!hash_equals(self::sign($time), $hmac)) {
            return false;
        }

        return (time() - (int) $time) >= $minSeconds;
    }

    private static function sign(string $time): string
    {
        return hash_hmac('sha256', $time, self::$secretKey ?? '');
    }
}
