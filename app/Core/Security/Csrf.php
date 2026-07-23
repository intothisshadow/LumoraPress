<?php

declare(strict_types=1);

namespace LumoraPress\Core\Security;

use RuntimeException;

/**
 * Per-action CSRF token issuance and verification. Tokens are single-use
 * and stored in the session, keyed by an action name so multiple forms on
 * the same page do not collide.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_tokens';

    public static function token(string $action = 'default'): string
    {
        self::ensureSession();

        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY][$action] = $token;

        return $token;
    }

    public static function verify(string $action, ?string $token): bool
    {
        self::ensureSession();

        $expected = $_SESSION[self::SESSION_KEY][$action] ?? null;

        if (!is_string($expected) || $token === null) {
            return false;
        }

        $isValid = hash_equals($expected, $token);

        if ($isValid) {
            unset($_SESSION[self::SESSION_KEY][$action]);
        }

        return $isValid;
    }

    public static function field(string $action = 'default'): string
    {
        $token = self::token($action);

        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
            . '">';
    }

    private static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Session must be started before using CSRF protection.');
        }

        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
    }
}
