<?php

/**
 * Limits how often one IP address can submit the sign-up form, and pauses sign-ups site-wide during a flood.
 *
 * @package LumoraPress
 * @subpackage Security
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Security;

use LumoraPress\Core\Database\Database;
use LumoraPress\Core\PressConfig;
use Throwable;

/**
 * A bot net rotates addresses, so a per-IP limit alone can't stop a flood: the second layer
 * counts every submission from every address and pauses registration once the total passes a
 * limit. Every submission is counted whether or not it was valid, so probing with bad input
 * costs a bot its allowance just as a good sign-up does. Fails open on a database error,
 * like the other throttles, so a broken table can't lock real visitors out.
 */
final class RegistrationThrottle
{
    public const DEFAULT_IP_LIMIT = 3;

    public const DEFAULT_FLOOD_LIMIT = 30;

    public const DEFAULT_PAUSE_MINUTES = 60;

    private const WINDOW_SECONDS = 3600;

    private const PAUSE_OPTION = 'registration_paused_until';

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly PressConfig $config,
    ) {
    }

    public function secondsUntilIpUnlocked(string $ipAddress): int
    {
        $limit = $this->ipLimit();

        if ($limit < 1) {
            return 0;
        }

        try {
            $rows = $this->database->fetchAll(
                'SELECT attempted_at FROM ' . $this->table() . '
                 WHERE ip_address = :ip AND attempted_at >= :window_start
                 ORDER BY attempted_at DESC LIMIT ' . $limit,
                ['ip' => $ipAddress, 'window_start' => date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS)],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return 0;
        }

        if (count($rows) < $limit) {
            return 0;
        }

        // The limit-th most recent submission is the one whose aging out frees a slot.
        return max(0, strtotime((string) $rows[$limit - 1]['attempted_at']) + self::WINDOW_SECONDS - time());
    }

    public function recordAttempt(string $ipAddress, ?string $email = null): void
    {
        try {
            $this->database->execute(
                'INSERT INTO ' . $this->table() . ' (ip_address, email, attempted_at) VALUES (:ip, :email, :attempted_at)',
                ['ip' => $ipAddress, 'email' => $email === null ? null : mb_substr($email, 0, 191), 'attempted_at' => date('Y-m-d H:i:s')],
            );

            // Old rows serve no purpose, and a flood would otherwise keep them forever.
            $this->database->execute(
                'DELETE FROM ' . $this->table() . ' WHERE attempted_at < :cutoff',
                ['cutoff' => date('Y-m-d H:i:s', time() - 86400)],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    /**
     * Seconds left of a site-wide pause, or 0 when sign-ups are open.
     */
    public function secondsPaused(): int
    {
        return max(0, (int) $this->config->option(self::PAUSE_OPTION, '0') - time());
    }

    /**
     * Pauses sign-ups when the last hour's total passes the flood limit.
     *
     * @return bool true only on the call that starts the pause, so the caller can log and
     *     notify once instead of on every blocked request
     */
    public function tripIfFlooded(): bool
    {
        $limit = (int) $this->config->option('registration_flood_limit', (string) self::DEFAULT_FLOOD_LIMIT);

        if ($limit < 1 || $this->secondsPaused() > 0) {
            return false;
        }

        try {
            $total = (int) $this->database->fetchColumn(
                'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE attempted_at >= :window_start',
                ['window_start' => date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS)],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return false;
        }

        if ($total < $limit) {
            return false;
        }

        $minutes = max(1, (int) $this->config->option('registration_pause_minutes', (string) self::DEFAULT_PAUSE_MINUTES));
        $this->config->setOption(self::PAUSE_OPTION, (string) (time() + $minutes * 60));

        return true;
    }

    public function resume(): void
    {
        $this->config->setOption(self::PAUSE_OPTION, '0');
    }

    private function ipLimit(): int
    {
        return (int) $this->config->option('registration_ip_limit', (string) self::DEFAULT_IP_LIMIT);
    }

    private function logFailure(Throwable $exception): void
    {
        error_log('[RegistrationThrottle] ' . $exception::class . ': ' . $exception->getMessage());
    }

    private function table(): string
    {
        return $this->tablePrefix . 'registration_attempts';
    }
}
