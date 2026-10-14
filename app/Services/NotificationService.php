<?php

/**
 * In-app notifications shown to signed-in users on the admin Notifications screen.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.18.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Hooks\HookManager;

/**
 * Deliberately generic (a type, a plain-text message, a link) rather than
 * comment-specific, so a plugin can notify users through the same inbox —
 * and, through the 'notification_created' action, relay every
 * notification somewhere else too (chat, push, email digests).
 * Every read/write method is scoped by user id, so one user can never
 * read or mark another user's notifications.
 */
final class NotificationService
{
    /** Old read notifications are pruned past this, so the table can't grow without bound. */
    private const READ_RETENTION_DAYS = 90;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    public function notify(int $userId, string $type, string $message, string $url = ''): void
    {
        $type = substr($type, 0, 50);

        // Skipped here rather than hidden later, so a muted type never raises the unread badge.
        if (in_array($type, $this->mutedTypes($userId), true)) {
            return;
        }

        $message = mb_substr($message, 0, 500);
        $url = substr($url, 0, 2048);

        $this->database->execute(
            'INSERT INTO ' . $this->table() . ' (user_id, type, message, url, created_at) VALUES (:user_id, :type, :message, :url, :created_at)',
            [
                'user_id' => $userId,
                'type' => $type,
                'message' => $message,
                'url' => $url,
                'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        $this->hooks?->doAction('notification_created', $userId, $type, $message, $url);
    }

    /**
     * Notifies every active user whose role grants $capability, each with their own copy so
     * one person reading or deleting theirs never affects anyone else's.
     *
     * @param ?int $exceptUserId Skipped, usually the person whose own action triggered the notice.
     * @return int how many users were notified
     */
    public function notifyUsersWithCapability(UserService $users, string $capability, string $type, string $message, string $url = '', ?int $exceptUserId = null): int
    {
        $count = 0;

        foreach ($users->listAll() as $user) {
            if ($user->id !== $exceptUserId && $user->can($capability)) {
                $this->notify($user->id, $type, $message, $url);
                ++$count;
            }
        }

        return $count;
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE user_id = :user_id AND read_at IS NULL',
            ['user_id' => $userId],
        );
    }

    /**
     * @return array{notifications: array<int, array<string, mixed>>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateForUser(int $userId, int $page = 1, int $perPage = 20, bool $unreadOnly = false, ?string $type = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $where = 'user_id = :user_id' . ($unreadOnly ? ' AND read_at IS NULL' : '');
        $params = ['user_id' => $userId];

        if ($type !== null && $type !== '') {
            $where .= ' AND type = :type';
            $params['type'] = $type;
        }

        $total = (int) $this->database->fetchColumn('SELECT COUNT(*) FROM ' . $this->table() . " WHERE {$where}", $params);
        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return [
            'notifications' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUser(int $id, int $userId): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM ' . $this->table() . ' WHERE id = :id AND user_id = :user_id',
            ['id' => $id, 'user_id' => $userId],
        );
    }

    public function markRead(int $id, int $userId): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . ' SET read_at = :now WHERE id = :id AND user_id = :user_id AND read_at IS NULL',
            ['now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id, 'user_id' => $userId],
        ) > 0;
    }

    public function markAllRead(int $userId): int
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . ' SET read_at = :now WHERE user_id = :user_id AND read_at IS NULL',
            ['now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'user_id' => $userId],
        );
    }

    public function deleteRead(int $userId): int
    {
        return $this->database->execute(
            'DELETE FROM ' . $this->table() . ' WHERE user_id = :user_id AND read_at IS NOT NULL',
            ['user_id' => $userId],
        );
    }

    public function pruneOldRead(): int
    {
        return $this->database->execute(
            'DELETE FROM ' . $this->table() . ' WHERE read_at IS NOT NULL AND read_at < :cutoff',
            ['cutoff' => (new DateTimeImmutable('-' . self::READ_RETENTION_DAYS . ' days'))->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Every type the user has at least one notification of, for the screen's type filter.
     *
     * @return array<int, string>
     */
    public function typesForUser(int $userId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT DISTINCT type FROM ' . $this->table() . ' WHERE user_id = :user_id ORDER BY type',
            ['user_id' => $userId],
        );

        return array_map(static fn (array $row): string => (string) $row['type'], $rows);
    }

    /**
     * @return array<int, string> types this user switched off on My Profile
     */
    public function mutedTypes(int $userId): array
    {
        $json = $this->database->fetchColumn(
            'SELECT notification_preferences FROM ' . $this->usersTable() . ' WHERE id = :id',
            ['id' => $userId],
        );
        $decoded = is_string($json) ? json_decode($json, true) : null;
        $muted = is_array($decoded) && is_array($decoded['muted'] ?? null) ? $decoded['muted'] : [];

        return array_values(array_unique(array_map('strval', $muted)));
    }

    /**
     * @param array<int, string> $types
     */
    public function setMutedTypes(int $userId, array $types): void
    {
        $types = array_values(array_unique(array_map(static fn (string $type): string => substr($type, 0, 50), $types)));

        $this->database->execute(
            'UPDATE ' . $this->usersTable() . ' SET notification_preferences = :preferences WHERE id = :id',
            ['preferences' => $types === [] ? null : json_encode(['muted' => $types]), 'id' => $userId],
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'notifications';
    }

    private function usersTable(): string
    {
        return $this->tablePrefix . 'users';
    }
}
