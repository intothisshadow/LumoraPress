<?php

/**
 * Login attempt throttling (brute-force protection), keyed by IP address and persisted in the database.
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
use Throwable;

/**
 * Login attempt throttling (brute-force protection), keyed by IP address
 * and persisted in the database rather than the session — an attacker
 * hammering the login form won't necessarily carry cookies between
 * requests, so a session-only counter would never actually engage.
 *
 * The attempted username is recorded alongside each failure for an
 * administrator's own visibility (e.g. "who is being targeted"), but the
 * lockout decision itself is keyed on IP address only, since that is the
 * signal that actually resists a distributed or username-rotating attack.
 */
final class LoginThrottle
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly int $maxAttempts = 5,
        private readonly int $windowSeconds = 900,
        private readonly int $lockoutSeconds = 900,
    ) {
    }

    /**
     * Seconds remaining before this IP may attempt to log in again, or 0
     * if it is not currently locked out.
     *
     * Fails open on a database error: if the throttle table itself can't
     * be read (e.g. a transient DB issue, or the migration hasn't run on
     * an older install yet), this returns 0 rather than blocking every
     * login attempt. The tradeoff is deliberate — a brief loss of
     * brute-force protection during an unrelated database problem is
     * preferable to locking every administrator out of a site that is
     * otherwise working (see CLAUDE.md's Error Handling: "log detailed
     * errors internally, show generic user-friendly messages publicly").
     * The error itself is still logged so the underlying problem is
     * visible.
     */
    public function secondsUntilUnlocked(string $ipAddress): int
    {
        try {
            $windowStart = date('Y-m-d H:i:s', time() - $this->windowSeconds);

            $row = $this->database->fetchOne(
                'SELECT COUNT(*) AS attempts, MAX(attempted_at) AS last_attempt_at
                 FROM ' . $this->table() . '
                 WHERE ip_address = :ip AND attempted_at >= :window_start',
                ['ip' => $ipAddress, 'window_start' => $windowStart],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return 0;
        }

        $attempts = (int) ($row['attempts'] ?? 0);
        $lastAttemptAt = $row['last_attempt_at'] ?? null;

        if ($attempts < $this->maxAttempts || $lastAttemptAt === null) {
            return 0;
        }

        $lockedUntil = strtotime((string) $lastAttemptAt) + $this->lockoutSeconds;

        return max(0, $lockedUntil - time());
    }

    public function isLocked(string $ipAddress): bool
    {
        return $this->secondsUntilUnlocked($ipAddress) > 0;
    }

    /**
     * Records a failed login attempt. Best-effort: a failure to write the
     * attempt log must never surface to (or block) the login flow itself —
     * the login attempt has already been rejected by Auth regardless of
     * whether we can log it.
     */
    public function recordFailure(string $ipAddress, ?string $username = null): void
    {
        try {
            $this->database->execute(
                'INSERT INTO ' . $this->table() . ' (ip_address, username, attempted_at)
                 VALUES (:ip, :username, :attempted_at)',
                [
                    'ip' => $ipAddress,
                    'username' => $username,
                    'attempted_at' => date('Y-m-d H:i:s'),
                ],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    /**
     * Clears attempt history for an IP address, called after a successful
     * login so a legitimate user who mistyped their password a few times
     * isn't left partway toward a lockout.
     */
    public function clear(string $ipAddress): void
    {
        try {
            $this->database->execute(
                'DELETE FROM ' . $this->table() . ' WHERE ip_address = :ip',
                ['ip' => $ipAddress],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    /**
     * @return array<int, array{ip_address: string, username: ?string, attempted_at: string}>
     *
     * Fails open (returns an empty list) on a database error, mirroring
     * secondsUntilUnlocked()'s own reasoning — a broken read here must
     * never crash the Maintenance > Logs admin screen.
     */
    public function recentAttempts(int $limit = 50): array
    {
        $limit = max(1, $limit);

        try {
            return $this->database->fetchAll(
                'SELECT ip_address, username, attempted_at
                 FROM ' . $this->table() . "
                 ORDER BY attempted_at DESC
                 LIMIT {$limit}",
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return [];
        }
    }

    private function logFailure(Throwable $exception): void
    {
        error_log('[LoginThrottle] ' . $exception::class . ': ' . $exception->getMessage());
    }

    private function table(): string
    {
        return $this->tablePrefix . 'login_attempts';
    }
}
