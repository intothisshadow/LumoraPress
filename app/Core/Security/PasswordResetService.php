<?php

declare(strict_types=1);

namespace LumoraPress\Core\Security;

use LumoraPress\Core\Database\Database;
use Throwable;

/**
 * Single-use password-reset tokens (LP-058), via the same selector/
 * validator pattern as RememberMeService — see that class's docblock for
 * why a split selector+validator beats one opaque token (timing-safe
 * lookup) and why a fast SHA-256 hash is correct here, not password_hash().
 *
 * Deliberately does not depend on UserService: every method here only ever
 * deals in a raw user_id, never hydrates a User — the caller (the
 * forgot-password/reset-password page handlers) already has UserService
 * for looking up the account and changing its password.
 *
 * At most one active token per user: issueToken() deletes any existing
 * tokens for that user before inserting a fresh one, so a new request
 * supersedes an old, unused link rather than leaving both valid.
 *
 * Known, accepted trade-off (not fully fixed here): a forgot-password
 * request for a registered email does strictly more work (a database
 * INSERT plus a mail() call) than one for an unregistered email, which is
 * a timing side-channel an attacker could use to enumerate registered
 * addresses. Eliminating it cleanly needs async/queued mail delivery,
 * which this codebase has no infrastructure for (no cron/queue exists —
 * see GitHubReleaseProvider's own "cron-free" docblock). PasswordResetThrottle
 * caps how many requests one IP can make, which bounds how much of this
 * side-channel an attacker can practically exploit, but does not close it
 * outright — the remaining gap is documented rather than engineered
 * around, the same treatment LoginThrottle's "fails open" trade-off gets.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly int $lifetimeSeconds = 3600,
        private readonly int $cooldownSeconds = 60,
    ) {
    }

    /**
     * Issues a fresh token for $userId and returns the raw
     * "selector:validator" pair to embed in the emailed reset link, or
     * null if a database error occurred or a token was already issued for
     * this user within the cooldown window (anti-flood — the caller should
     * treat this identically to success, since the earlier email is still
     * valid and re-sending would only enable mail-bombing an inbox).
     */
    public function issueToken(int $userId): ?string
    {
        try {
            $lastIssuedAt = $this->database->fetchColumn(
                'SELECT created_at FROM ' . $this->table() . ' WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 1',
                ['user_id' => $userId],
            );

            if (is_string($lastIssuedAt) && (time() - strtotime($lastIssuedAt)) < $this->cooldownSeconds) {
                return null;
            }

            $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE user_id = :user_id', ['user_id' => $userId]);

            $selector = bin2hex(random_bytes(12));
            $validator = bin2hex(random_bytes(32));

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

    /**
     * Non-mutating check used to decide whether to render the "set a new
     * password" form (never deletes the row) — a link a security scanner
     * or email client pre-fetches must not burn the token before the user
     * actually submits the form.
     */
    public function findValidToken(string $tokenValue): ?int
    {
        return $this->validateInternal($tokenValue)['userId'] ?? null;
    }

    /**
     * Validates and deletes the token in one step (single-use) — only ever
     * called when actually processing a password change.
     */
    public function consume(string $tokenValue): ?int
    {
        $result = $this->validateInternal($tokenValue);

        if ($result === null) {
            return null;
        }

        $this->deleteBySelector($result['selector']);

        return $result['userId'];
    }

    /**
     * Deletes every outstanding token for a user — called after a
     * successful reset so an older, still-valid link (if any) can't also
     * be used afterward.
     */
    public function invalidateForUser(int $userId): void
    {
        try {
            $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE user_id = :user_id', ['user_id' => $userId]);
        } catch (Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    /**
     * @return array{userId: int, selector: string}|null
     */
    private function validateInternal(string $tokenValue): ?array
    {
        $parts = explode(':', $tokenValue, 2);

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
            $this->deleteBySelector($selector);

            return null;
        }

        if (!hash_equals((string) $row['token_hash'], hash('sha256', $validator))) {
            return null;
        }

        return ['userId' => (int) $row['user_id'], 'selector' => $selector];
    }

    private function deleteBySelector(string $selector): void
    {
        try {
            $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE selector = :selector', ['selector' => $selector]);
        } catch (Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    private function logFailure(Throwable $exception): void
    {
        error_log('[PasswordResetService] ' . $exception::class . ': ' . $exception->getMessage());
    }

    private function table(): string
    {
        return $this->tablePrefix . 'password_resets';
    }
}
