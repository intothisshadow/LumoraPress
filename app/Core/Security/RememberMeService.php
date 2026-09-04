<?php

/**
 * "Remember Me" persistent login via the classic selector/validator cookie pattern.
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

use LumoraPress\Core\Database\Database;
use LumoraPress\Models\User;
use LumoraPress\Services\UserService;
use Throwable;

/**
 * "Remember Me" persistent login via the classic selector/validator cookie
 * pattern rather than a single opaque token:
 *
 *  - The selector is looked up directly (a public identifier, like a
 *    username) and is unique-indexed.
 *  - The validator is a separate random value; only its SHA-256 hash is
 *    stored, the raw value lives only in the visitor's cookie. Comparison
 *    uses hash_equals() to stay timing-safe.
 *
 * A single opaque token compared via `WHERE token = ?` would leak timing
 * information through the index scan itself; the split avoids that.
 *
 * SHA-256 (not password_hash()) is correct here because the validator is
 * 32 cryptographically random bytes, not human-guessable — brute force
 * isn't the threat model, so a slow hash would only waste CPU. This does
 * not weaken the password_hash() rule for `{prefix}users`.
 *
 * Every token is single-use: validate() rotates it on success, so a
 * stolen-then-reused cookie only works once. If a selector is found but
 * its validator doesn't match — the signature of a stolen cookie replayed
 * after legitimate rotation — every token for that user is revoked.
 *
 * Unlike LoginThrottle, this fails *closed* on a database error: a failed
 * lookup just falls back to the normal login form rather than locking
 * anyone out. Auth decisions fail closed; throttle gates fail open.
 */
final class RememberMeService
{
    private const COOKIE_NAME = 'lumora_press_remember';

    public function __construct(
        private readonly Database $database,
        private readonly UserService $users,
        private readonly string $tablePrefix,
        private readonly bool $secureCookies,
        private readonly int $lifetimeSeconds = 30 * 24 * 60 * 60,
    ) {
    }

    /**
     * Issues a fresh token for the given user and sets the remember-me
     * cookie on the response. Called after a successful password login
     * when the visitor checked "Remember Me".
     */
    public function rememberUser(int $userId): void
    {
        $cookieValue = $this->issueToken($userId);

        if ($cookieValue !== null) {
            $this->setCookie($cookieValue);
        }
    }

    /**
     * Attempts to authenticate from the remember-me cookie, if present.
     * Returns the User on success (and rotates the cookie to a fresh
     * token), or null if there is no cookie, it's invalid/expired, or the
     * lookup failed — in every null case, any existing cookie is cleared
     * so a bad value isn't retried on every subsequent request.
     */
    public function attemptFromCookie(): ?User
    {
        $cookieValue = $_COOKIE[self::COOKIE_NAME] ?? null;

        if (!is_string($cookieValue) || $cookieValue === '') {
            return null;
        }

        $result = $this->validate($cookieValue);

        if ($result === null) {
            $this->clearCookie();

            return null;
        }

        $this->setCookie($result['cookieValue']);

        return $result['user'];
    }

    /**
     * Revokes the token behind the current cookie (if any) and clears the
     * cookie. Called on logout so signing out also ends persistent access
     * on this device, not just the current session.
     */
    public function forgetCurrentCookie(): void
    {
        $cookieValue = $_COOKIE[self::COOKIE_NAME] ?? null;

        if (is_string($cookieValue) && $cookieValue !== '') {
            $selector = $this->parseSelector($cookieValue);

            if ($selector !== null) {
                $this->forgetBySelector($selector);
            }
        }

        $this->clearCookie();
    }

    /**
     * Revokes every remember-me token for a user, e.g. as a precaution
     * when token reuse suggests a cookie was stolen.
     */
    public function forgetUserTokens(int $userId): void
    {
        try {
            $this->database->execute(
                'DELETE FROM ' . $this->table() . ' WHERE user_id = :user_id',
                ['user_id' => $userId],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    /**
     * The pure validation/rotation core, free of $_COOKIE/setcookie() I/O
     * so it's directly testable — setcookie() only affects the *next*
     * request, so a test can't otherwise observe rememberUser()'s effect.
     *
     * @return array{user: User, cookieValue: string}|null
     */
    public function validate(string $cookieValue): ?array
    {
        $parts = explode(':', $cookieValue, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$selector, $validator] = $parts;

        try {
            $row = $this->database->fetchOne(
                'SELECT * FROM ' . $this->table() . ' WHERE selector = :selector',
                ['selector' => $selector],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return null;
        }

        if ($row === null) {
            return null;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            $this->forgetBySelector($selector);

            return null;
        }

        if (!hash_equals((string) $row['token_hash'], hash('sha256', $validator))) {
            $this->forgetUserTokens((int) $row['user_id']);

            return null;
        }

        $user = $this->users->findById((int) $row['user_id']);

        $this->forgetBySelector($selector);

        if ($user === null) {
            return null;
        }

        $newCookieValue = $this->issueToken($user->id);

        if ($newCookieValue === null) {
            return null;
        }

        return ['user' => $user, 'cookieValue' => $newCookieValue];
    }

    /**
     * Issues a fresh token and returns the raw "selector:validator" pair
     * (or null on a database failure), without touching $_COOKIE — the
     * pure counterpart to validate() described above.
     */
    public function issueToken(int $userId): ?string
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));

        try {
            $this->database->execute(
                'INSERT INTO ' . $this->table() . ' (user_id, selector, token_hash, expires_at, created_at)
                 VALUES (:user_id, :selector, :token_hash, :expires_at, :created_at)',
                [
                    'user_id' => $userId,
                    'selector' => $selector,
                    'token_hash' => hash('sha256', $validator),
                    'expires_at' => date('Y-m-d H:i:s', time() + $this->lifetimeSeconds),
                    'created_at' => date('Y-m-d H:i:s'),
                ],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return null;
        }

        return $selector . ':' . $validator;
    }

    private function forgetBySelector(string $selector): void
    {
        try {
            $this->database->execute(
                'DELETE FROM ' . $this->table() . ' WHERE selector = :selector',
                ['selector' => $selector],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    private function parseSelector(string $cookieValue): ?string
    {
        $parts = explode(':', $cookieValue, 2);

        return $parts[0] !== '' ? $parts[0] : null;
    }

    private function setCookie(string $value): void
    {
        setcookie(self::COOKIE_NAME, $value, [
            'expires' => time() + $this->lifetimeSeconds,
            'path' => '/',
            'domain' => '',
            'secure' => $this->secureCookies,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 42000,
            'path' => '/',
            'domain' => '',
            'secure' => $this->secureCookies,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        unset($_COOKIE[self::COOKIE_NAME]);
    }

    private function logFailure(Throwable $exception): void
    {
        error_log('[RememberMeService] ' . $exception::class . ': ' . $exception->getMessage());
    }

    private function table(): string
    {
        return $this->tablePrefix . 'remember_tokens';
    }
}
