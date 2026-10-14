<?php

/**
 * Finds and clears sign-up accounts that were never used, and old trashed users.
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

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Kernel;
use LumoraPress\Core\PressConfig;
use LumoraPress\Models\UserRole;
use LumoraPress\Services\UserService;

/**
 * An account is "inactive" only when every safeguard holds: it came from the public sign-up, is
 * a Subscriber or Contributor, is old enough, has not signed in (or not lately), and owns or
 * appears in no content or per-user data, since deleting such an account would silently drop it.
 * Each safeguard is a NOT EXISTS over one table, so a flooded site is one query per call, never
 * one per user. Clean-up methods take a limit and return how many they handled, so a caller can
 * run them in batches until nothing is left.
 */
final class AccountSweepService
{
    public const DEFAULT_AGE_DAYS = 30;

    public const DEFAULT_SIGN_IN_DAYS = 0;

    public const DEFAULT_TRASHED_DAYS = 30;

    public const BATCH_SIZE = 200;

    /** @var array<string, string> table (no prefix) => column holding a user id */
    private const USER_DATA = [
        'posts' => 'author_id',
        'pages' => 'author_id',
        'comments' => 'user_id',
        'media' => 'uploaded_by',
        'revisions' => 'author_id',
        'comment_subscriptions' => 'user_id',
        'comment_reactions' => 'user_id',
        'comment_reports' => 'user_id',
        'notifications' => 'user_id',
    ];

    /**
     * @param ?\Closure(int): void $onDeleted Called after each permanent delete, e.g. to forget the user's remember-me tokens.
     */
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly PressConfig $config,
        private readonly UserService $users,
        private readonly ?\Closure $onDeleted = null,
    ) {
    }

    public static function fromKernel(Kernel $kernel): self
    {
        return new self(
            $kernel->database,
            (string) $kernel->config->get('table_prefix', 'lp_'),
            $kernel->config,
            $kernel->users,
            static fn (int $userId) => $kernel->rememberMe->forgetUserTokens($userId),
        );
    }

    public function inactiveAgeDays(): int
    {
        return max(0, (int) $this->config->option('sweep_inactive_age_days', (string) self::DEFAULT_AGE_DAYS));
    }

    /** 0 means the account must never have signed in. */
    public function inactiveSignInDays(): int
    {
        return max(0, (int) $this->config->option('sweep_inactive_signin_days', (string) self::DEFAULT_SIGN_IN_DAYS));
    }

    public function trashedUsersDays(): int
    {
        return max(0, (int) $this->config->option('sweep_trashed_users_days', (string) self::DEFAULT_TRASHED_DAYS));
    }

    public function countInactive(int $minAgeDays, int $signInDays, int $exceptUserId = 0): int
    {
        [$where, $params] = $this->inactiveWhere($minAgeDays, $signInDays, $exceptUserId);

        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM ' . $this->table('users') . ' u WHERE ' . $where, $params);
    }

    /**
     * @return array<int, array{id: int, username: string, createdAt: string}> oldest sign-ups first
     */
    public function sampleInactive(int $minAgeDays, int $signInDays, int $exceptUserId = 0, int $limit = 20): array
    {
        $rows = $this->inactiveRows($minAgeDays, $signInDays, $exceptUserId, $limit);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'createdAt' => (string) $row['created_at'],
        ], $rows);
    }

    /**
     * Moves up to $limit inactive accounts to the Users trash, where each can still be restored.
     *
     * @return int how many were moved
     */
    public function trashInactive(int $minAgeDays, int $signInDays, int $exceptUserId = 0, int $limit = self::BATCH_SIZE): int
    {
        $moved = 0;

        foreach ($this->inactiveRows($minAgeDays, $signInDays, $exceptUserId, $limit) as $row) {
            if ($this->users->trash((int) $row['id'])) {
                ++$moved;
            }
        }

        return $moved;
    }

    /** Trashed users old enough to delete for good, and safe to (see deletableTrashedWhere()). */
    public function countOldTrashed(int $days): int
    {
        [$where, $params] = $this->deletableTrashedWhere($days);

        return (int) $this->database->fetchColumn('SELECT COUNT(*) FROM ' . $this->table('users') . ' u WHERE ' . $where, $params);
    }

    /**
     * @return int how many were deleted
     */
    public function deleteOldTrashed(int $days, int $limit = self::BATCH_SIZE): int
    {
        [$where, $params] = $this->deletableTrashedWhere($days);
        $rows = $this->database->fetchAll(
            'SELECT u.id FROM ' . $this->table('users') . ' u WHERE ' . $where . ' ORDER BY u.id ASC LIMIT ' . max(1, $limit),
            $params,
        );
        $deleted = 0;

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            if ($this->users->delete($id)) {
                ++$deleted;

                if ($this->onDeleted !== null) {
                    ($this->onDeleted)($id);
                }
            }
        }

        return $deleted;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function inactiveRows(int $minAgeDays, int $signInDays, int $exceptUserId, int $limit): array
    {
        [$where, $params] = $this->inactiveWhere($minAgeDays, $signInDays, $exceptUserId);

        return $this->database->fetchAll(
            'SELECT u.id, u.username, u.created_at FROM ' . $this->table('users') . ' u WHERE ' . $where . ' ORDER BY u.created_at ASC, u.id ASC LIMIT ' . max(1, $limit),
            $params,
        );
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function inactiveWhere(int $minAgeDays, int $signInDays, int $exceptUserId): array
    {
        $conditions = [
            'u.trashed_at IS NULL',
            "u.role IN ('" . UserRole::Subscriber->value . "', '" . UserRole::Contributor->value . "')",
            "u.registration_source = 'signup'",
            'u.created_at < :created_cutoff',
            'u.id <> :except_id',
        ];
        $params = [
            'created_cutoff' => $this->cutoff($minAgeDays),
            'except_id' => $exceptUserId,
        ];

        if ($signInDays > 0) {
            $conditions[] = '(u.last_active_at IS NULL OR u.last_active_at < :signin_cutoff)';
            $params['signin_cutoff'] = $this->cutoff($signInDays);
        } else {
            $conditions[] = 'u.last_active_at IS NULL';
        }

        foreach (self::USER_DATA as $table => $column) {
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM ' . $this->table($table) . " t_{$table} WHERE t_{$table}.{$column} = u.id)";
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * Mirrors what the Users screen refuses: a user who authored posts or pages is never deleted
     * (there is no reassignment), nor is the last Administrator.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function deletableTrashedWhere(int $days): array
    {
        $conditions = [
            'u.trashed_at IS NOT NULL',
            'u.trashed_at < :trashed_cutoff',
            'NOT EXISTS (SELECT 1 FROM ' . $this->table('posts') . ' t_posts WHERE t_posts.author_id = u.id)',
            'NOT EXISTS (SELECT 1 FROM ' . $this->table('pages') . ' t_pages WHERE t_pages.author_id = u.id)',
        ];

        if ($this->users->countByRole(UserRole::Administrator) <= 1) {
            $conditions[] = "u.role <> '" . UserRole::Administrator->value . "'";
        }

        return [implode(' AND ', $conditions), ['trashed_cutoff' => $this->cutoff($days)]];
    }

    private function cutoff(int $days): string
    {
        return (new DateTimeImmutable())->modify('-' . max(0, $days) . ' days')->format('Y-m-d H:i:s');
    }

    private function table(string $name): string
    {
        return $this->tablePrefix . $name;
    }
}
