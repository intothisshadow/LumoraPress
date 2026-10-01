<?php

/**
 * Per-action CSRF token issuance and verification.
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
 * Per-action CSRF token issuance and verification. Tokens are single-use
 * and stored in the session, keyed by an action name so multiple forms on
 * the same page do not collide. Each action keeps a short pool of
 * outstanding tokens rather than one, so several tabs (e.g. two posts
 * open for editing) can each hold a valid token for the same action;
 * with a single slot, the most recently loaded tab would invalidate the
 * others.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_tokens';

    // Bounds session growth while leaving room for many open tabs.
    private const MAX_TOKENS_PER_ACTION = 30;

    public static function token(string $action = 'default'): string
    {
        self::ensureSession();

        $token = bin2hex(random_bytes(32));
        $pool = self::pool($action);
        $pool[] = $token;
        $_SESSION[self::SESSION_KEY][$action] = array_slice($pool, -self::MAX_TOKENS_PER_ACTION);

        return $token;
    }

    public static function verify(string $action, ?string $token): bool
    {
        self::ensureSession();

        if ($token === null) {
            return false;
        }

        $pool = self::pool($action);

        foreach ($pool as $index => $expected) {
            if (hash_equals($expected, $token)) {
                unset($pool[$index]);
                $_SESSION[self::SESSION_KEY][$action] = array_values($pool);

                return true;
            }
        }

        return false;
    }

    public static function field(string $action = 'default'): string
    {
        $token = self::token($action);

        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
            . '">';
    }

    /**
     * @return list<string>
     */
    private static function pool(string $action): array
    {
        $stored = $_SESSION[self::SESSION_KEY][$action] ?? [];

        // A string is the single-slot format sessions created before the pool existed still hold.
        if (is_string($stored)) {
            return [$stored];
        }

        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
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
