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

    // Long enough for an editor left open through a working day, short
    // enough that a token leaked into old logs or history is worthless.
    private const TOKEN_LIFETIME_SECONDS = 43200;

    /** @var (callable(string): void)|null */
    private static $failureListener = null;

    /**
     * Registers a callback invoked with the action name whenever verify()
     * rejects a token, so failures can be logged without every caller
     * having to remember to.
     *
     * @param (callable(string): void)|null $listener
     */
    public static function setFailureListener(?callable $listener): void
    {
        self::$failureListener = $listener;
    }

    private static bool $issuedThisRequest = false;

    /**
     * Whether any token was minted during this request. A page that embeds
     * a single-use token is specific to the visitor's session, so a shared
     * cache must never store it; the response layer checks this instead of
     * every form having to opt out of caching itself.
     */
    public static function wasIssued(): bool
    {
        return self::$issuedThisRequest;
    }

    public static function token(string $action = 'default'): string
    {
        self::ensureSession();
        self::$issuedThisRequest = true;

        $token = bin2hex(random_bytes(32));
        $pool = self::pool($action);
        $pool[] = ['token' => $token, 'issued_at' => time()];
        $_SESSION[self::SESSION_KEY][$action] = array_slice($pool, -self::MAX_TOKENS_PER_ACTION);

        return $token;
    }

    public static function verify(string $action, ?string $token): bool
    {
        self::ensureSession();

        if ($token !== null) {
            $pool = self::pool($action);

            foreach ($pool as $index => $entry) {
                if (hash_equals($entry['token'], $token)) {
                    // Single-use whether or not it turns out to be stale.
                    unset($pool[$index]);
                    $_SESSION[self::SESSION_KEY][$action] = array_values($pool);

                    if ((time() - $entry['issued_at']) <= self::TOKEN_LIFETIME_SECONDS) {
                        return true;
                    }

                    break;
                }
            }
        }

        if (self::$failureListener !== null) {
            (self::$failureListener)($action);
        }

        return false;
    }

    /**
     * Discards every outstanding token. Called when the session's
     * privilege level changes (login/logout) so a token issued to the
     * previous identity can't be replayed by the new one.
     */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_KEY] = [];
        }
    }

    public static function field(string $action = 'default'): string
    {
        $token = self::token($action);

        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
            . '">';
    }

    /**
     * Expired entries are dropped here so they can't crowd live tokens out
     * of the bounded pool.
     *
     * @return list<array{token: string, issued_at: int}>
     */
    private static function pool(string $action): array
    {
        $stored = $_SESSION[self::SESSION_KEY][$action] ?? [];

        // A string is the single-slot format sessions created before the pool existed still hold.
        if (is_string($stored)) {
            $stored = [$stored];
        }

        if (!is_array($stored)) {
            return [];
        }

        $now = time();
        $pool = [];

        foreach ($stored as $entry) {
            // A bare string is a token from before issue times were recorded; treat it as issued now.
            if (is_string($entry)) {
                $pool[] = ['token' => $entry, 'issued_at' => $now];
            } elseif (is_array($entry) && is_string($entry['token'] ?? null) && is_int($entry['issued_at'] ?? null)) {
                $pool[] = ['token' => $entry['token'], 'issued_at' => $entry['issued_at']];
            }
        }

        return array_values(array_filter(
            $pool,
            static fn (array $entry): bool => ($now - $entry['issued_at']) <= self::TOKEN_LIFETIME_SECONDS,
        ));
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
