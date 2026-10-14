<?php

/**
 * Sends Administrators an inbox reminder when unused sign-up accounts pile up.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LumoraSweep;

use LumoraPress\Core\Database\Database;
use LumoraPress\Core\PressConfig;
use LumoraPress\Services\NotificationService;
use LumoraPress\Services\UserService;

/**
 * There is no cron, so this runs on an administrator's admin page load, but the count is cached
 * for an hour so it never costs a query per request. Sending is rate-limited by site options
 * rather than the notifications table, because a read notification can be deleted and the table
 * would then forget it had ever reminded anyone.
 */
final class SweepReminderService
{
    public const TYPE = 'sweep_reminder';

    public const DEFAULT_THRESHOLD = 25;

    public const DEFAULT_INTERVAL_DAYS = 7;

    private const COUNT_CACHE_SECONDS = 3600;

    public function __construct(
        private readonly PressConfig $config,
        private readonly AccountSweepService $accounts,
        private readonly NotificationService $notifications,
        private readonly UserService $users,
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    public function threshold(): int
    {
        return max(0, (int) $this->config->option('sweep_reminder_threshold', (string) self::DEFAULT_THRESHOLD));
    }

    public function intervalDays(): int
    {
        return max(1, (int) $this->config->option('sweep_reminder_interval_days', (string) self::DEFAULT_INTERVAL_DAYS));
    }

    /**
     * @return bool whether a reminder was sent
     */
    public function maybeRemind(): bool
    {
        $threshold = $this->threshold();

        if ($threshold === 0) {
            return false;
        }

        $count = $this->cachedCount();

        if ($count < $threshold || $this->hasUnreadReminder() || !$this->intervalHasPassed()) {
            return false;
        }

        $this->config->setOption('sweep_reminder_sent_at', (string) time());
        $this->notifications->notifyUsersWithCapability(
            $this->users,
            'manage_options',
            self::TYPE,
            $count === 1 ? __('1 sign-up looks unused. Review it in Sweep.') : sprintf(__('%d sign-ups look unused. Review them in Sweep.'), $count),
            admin_url('maintenance/tools') . '?tab=sweep',
        );

        return true;
    }

    private function cachedCount(): int
    {
        $checkedAt = (int) $this->config->option('sweep_reminder_checked_at', '0');

        if (time() - $checkedAt < self::COUNT_CACHE_SECONDS) {
            return (int) $this->config->option('sweep_reminder_count', '0');
        }

        $count = $this->accounts->countInactive($this->accounts->inactiveAgeDays(), $this->accounts->inactiveSignInDays());
        $this->config->setOption('sweep_reminder_checked_at', (string) time());
        $this->config->setOption('sweep_reminder_count', (string) $count);

        return $count;
    }

    private function hasUnreadReminder(): bool
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->tablePrefix . 'notifications WHERE type = :type AND read_at IS NULL',
            ['type' => self::TYPE],
        ) > 0;
    }

    private function intervalHasPassed(): bool
    {
        $sentAt = (int) $this->config->option('sweep_reminder_sent_at', '0');

        return time() - $sentAt >= $this->intervalDays() * 86400;
    }
}
