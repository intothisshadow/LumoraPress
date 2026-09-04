<?php

/**
 * IP-based rate limiting for the "forgot password" request form (LP-058).
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
 * IP-based rate limiting for the "forgot password" form, same shape as
 * LoginThrottle but bounding total reset requests per IP regardless of
 * outcome — this blunts PasswordResetService's timing side-channel by
 * making large-scale account enumeration impractical, without closing it.
 * Every attempt is recorded post-CSRF, whether or not the email exists, so
 * an attacker can't dodge the counter by only probing unregistered addresses.
 *
 * Unlike LoginThrottle there's no clear()-on-success: this bounds total
 * request volume, not failures against one account.
 *
 * Fails open on a database error, same reasoning as LoginThrottle.
 */
final class PasswordResetThrottle
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly int $maxAttempts = 5,
        private readonly int $windowSeconds = 900,
        private readonly int $lockoutSeconds = 900,
    ) {
    }

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
     * Records a reset request from this IP — called once per submitted
     * form, regardless of whether the email turns out to be registered.
     */
    public function recordAttempt(string $ipAddress, ?string $email = null): void
    {
        try {
            $this->database->execute(
                'INSERT INTO ' . $this->table() . ' (ip_address, email, attempted_at)
                 VALUES (:ip, :email, :attempted_at)',
                [
                    'ip' => $ipAddress,
                    'email' => $email,
                    'attempted_at' => date('Y-m-d H:i:s'),
                ],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    private function logFailure(Throwable $exception): void
    {
        error_log('[PasswordResetThrottle] ' . $exception::class . ': ' . $exception->getMessage());
    }

    private function table(): string
    {
        return $this->tablePrefix . 'password_reset_requests';
    }
}
