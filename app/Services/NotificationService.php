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

/**
 * Deliberately generic (a type, a plain-text message, a link) rather than
 * comment-specific, so a plugin can notify users through the same inbox.
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
    ) {
    }

    public function notify(int $userId, string $type, string $message, string $url = ''): void
    {
        $this->database->execute(
            'INSERT INTO ' . $this->table() . ' (user_id, type, message, url, created_at) VALUES (:user_id, :type, :message, :url, :created_at)',
            [
                'user_id' => $userId,
                'type' => substr($type, 0, 50),
                'message' => mb_substr($message, 0, 500),
                'url' => substr($url, 0, 2048),
                'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
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
    public function paginateForUser(int $userId, int $page = 1, int $perPage = 20, bool $unreadOnly = false): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $where = 'user_id = :user_id' . ($unreadOnly ? ' AND read_at IS NULL' : '');
        $params = ['user_id' => $userId];

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

    private function table(): string
    {
        return $this->tablePrefix . 'notifications';
    }
}
