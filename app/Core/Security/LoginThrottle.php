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
 * Login attempt throttling, keyed by IP and persisted in the database
 * rather than the session — an attacker hammering the login form won't
 * necessarily carry cookies, so a session-only counter would never engage.
 *
 * The attempted username is logged for admin visibility, but the lockout
 * decision itself is IP-only, since that resists a username-rotating attack.
 */
final class LoginThrottle
{
    // How far back repeat lockouts are remembered when lengthening a lockout.
    private const REPEAT_HISTORY_SECONDS = 86400;

    private const MAX_LOCKOUT_MULTIPLIER = 16;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly int $maxAttempts = 5,
        private readonly int $windowSeconds = 900,
        private readonly int $lockoutSeconds = 900,
        private readonly bool $progressive = true,
    ) {
    }

    /**
     * Seconds remaining before this IP may attempt to log in again, or 0
     * if not locked out.
     *
     * Fails open on a database error: a brief loss of brute-force
     * protection is preferable to locking every admin out of an otherwise
     * working site. The error is still logged.
     */
    public function secondsUntilUnlocked(string $ipAddress): int
    {
        try {
            $historyStart = date('Y-m-d H:i:s', time() - max($this->windowSeconds, self::REPEAT_HISTORY_SECONDS));

            $summary = $this->database->fetchOne(
                'SELECT COUNT(*) AS history_attempts, MAX(attempted_at) AS last_attempt_at
                 FROM ' . $this->table() . '
                 WHERE ip_address = :ip AND attempted_at >= :history_start',
                ['ip' => $ipAddress, 'history_start' => $historyStart],
            );

            $lastAttemptAt = $summary['last_attempt_at'] ?? null;

            if ($lastAttemptAt === null) {
                return 0;
            }

            // Counted back from the last attempt rather than from now, so a
            // lockout longer than the window isn't cut short as the
            // failures that caused it age out of it.
            $burstStart = date('Y-m-d H:i:s', strtotime((string) $lastAttemptAt) - $this->windowSeconds);

            $attempts = (int) $this->database->fetchColumn(
                'SELECT COUNT(*) FROM ' . $this->table() . '
                 WHERE ip_address = :ip AND attempted_at >= :burst_start',
                ['ip' => $ipAddress, 'burst_start' => $burstStart],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return 0;
        }

        if ($attempts < $this->maxAttempts) {
            return 0;
        }

        $row = $summary;
        $lockedUntil = strtotime((string) $lastAttemptAt) + ($this->lockoutSeconds * $this->lockoutMultiplier((int) ($row['history_attempts'] ?? 0)));

        return max(0, $lockedUntil - time());
    }

    /**
     * Each further batch of failures from the same IP within a day doubles
     * the lockout (1x, 2x, 4x... capped), so a patient attacker pays more
     * for every cycle while a user who simply forgot a password once is
     * only ever locked out for the base duration.
     */
    private function lockoutMultiplier(int $historyAttempts): int
    {
        if (!$this->progressive) {
            return 1;
        }

        $tier = max(1, intdiv($historyAttempts, max(1, $this->maxAttempts)));

        return min(self::MAX_LOCKOUT_MULTIPLIER, 2 ** ($tier - 1));
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
     * Fails open (empty list) on a database error — a broken read must
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
