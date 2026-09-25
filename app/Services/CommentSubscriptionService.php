<?php

/**
 * Email subscriptions to a post's or page's comment thread ("notify me of new comments").
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
use InvalidArgumentException;
use LumoraPress\Core\Database\Database;
use RuntimeException;

/**
 * A subscription is keyed by (post or page, email), so one address watches
 * a thread at most once however many comments it leaves there. Guest
 * subscriptions start 'pending' until confirmed through an emailed link;
 * a signed-in user's own account email starts 'active'. Rows are plain
 * arrays, the same lightweight convention RedirectService uses.
 *
 * The token is stored in plain text because every notification email has
 * to embed it in an unsubscribe link; it only ever grants confirming or
 * cancelling this one subscription.
 */
final class CommentSubscriptionService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    /**
     * Returns the existing subscription unchanged when $email already
     * watches this thread — re-subscribing never resets a confirmed row
     * back to pending or issues a new token.
     *
     * @return array<string, mixed>
     */
    public function subscribe(string $contentType, int $contentId, string $email, ?int $userId, bool $active, ?int $sourceCommentId = null): array
    {
        $column = self::contentColumn($contentType);
        $email = strtolower(trim($email));

        $existing = $this->findFor($contentType, $contentId, $email);

        if ($existing !== null) {
            if ($active && $existing['status'] !== self::STATUS_ACTIVE) {
                $this->confirm((int) $existing['id']);

                return $this->find((int) $existing['id']) ?? $existing;
            }

            // Still unconfirmed: tie it to the newest comment, since the one
            // that created it may have been rejected and never send a confirmation.
            if ($existing['status'] === self::STATUS_PENDING && $sourceCommentId !== null) {
                $this->database->execute(
                    'UPDATE ' . $this->table() . ' SET source_comment_id = :source_comment_id WHERE id = :id',
                    ['source_comment_id' => $sourceCommentId, 'id' => (int) $existing['id']],
                );

                return $this->find((int) $existing['id']) ?? $existing;
            }

            return $existing;
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $id = (int) $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . " ({$column}, email, user_id, token, status, source_comment_id, created_at, confirmed_at)
             VALUES (:content_id, :email, :user_id, :token, :status, :source_comment_id, :created_at, :confirmed_at)",
            [
                'content_id' => $contentId,
                'email' => $email,
                'user_id' => $userId,
                'token' => bin2hex(random_bytes(32)),
                'status' => $active ? self::STATUS_ACTIVE : self::STATUS_PENDING,
                'source_comment_id' => $sourceCommentId,
                'created_at' => $now,
                'confirmed_at' => $active ? $now : null,
            ],
        );

        return $this->find($id) ?? throw new RuntimeException('Failed to load the subscription that was just created.');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE token = :token', ['token' => $token]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findFor(string $contentType, int $contentId, string $email): ?array
    {
        $column = self::contentColumn($contentType);

        return $this->database->fetchOne(
            'SELECT * FROM ' . $this->table() . " WHERE {$column} = :content_id AND email = :email",
            ['content_id' => $contentId, 'email' => strtolower(trim($email))],
        );
    }

    public function confirm(int $id): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . ' SET status = :status, confirmed_at = :now WHERE id = :id',
            ['status' => self::STATUS_ACTIVE, 'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        ) > 0;
    }

    public function delete(int $id): bool
    {
        return $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) > 0;
    }

    /**
     * Removes subscriptions whose post or page no longer exists. Checks
     * the content tables rather than taking an id, because the
     * post_deleted/page_deleted hooks also fire on trash, and a trashed
     * thread's watchers must survive a restore. Safe to run any time.
     *
     * @return int rows removed
     */
    public function deleteOrphaned(): int
    {
        return $this->database->execute(
            'DELETE FROM ' . $this->table()
            . ' WHERE (post_id IS NOT NULL AND post_id NOT IN (SELECT id FROM ' . $this->tablePrefix . 'posts))'
            . ' OR (page_id IS NOT NULL AND page_id NOT IN (SELECT id FROM ' . $this->tablePrefix . 'pages))',
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeFor(string $contentType, int $contentId): array
    {
        $column = self::contentColumn($contentType);

        return $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " WHERE {$column} = :content_id AND status = :status",
            ['content_id' => $contentId, 'status' => self::STATUS_ACTIVE],
        );
    }

    /**
     * Pending subscriptions created alongside $commentId — their
     * confirmation email waits until that comment is approved, so a
     * comment held as spam never causes mail to an address its author
     * typed in.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingForSourceComment(int $commentId): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . ' WHERE source_comment_id = :comment_id AND status = :status',
            ['comment_id' => $commentId, 'status' => self::STATUS_PENDING],
        );
    }

    /**
     * Every active thread $email (or $userId's account) watches, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeForSubscriber(?int $userId, string $email): array
    {
        $params = ['status' => self::STATUS_ACTIVE, 'email' => strtolower(trim($email))];
        $match = 'email = :email';

        if ($userId !== null) {
            $match .= ' OR user_id = :user_id';
            $params['user_id'] = $userId;
        }

        return $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . " WHERE status = :status AND ({$match}) ORDER BY created_at DESC",
            $params,
        );
    }

    /**
     * Only ever one of two fixed column names, so it's safe to interpolate.
     */
    private static function contentColumn(string $contentType): string
    {
        return match ($contentType) {
            'post' => 'post_id',
            'page' => 'page_id',
            default => throw new InvalidArgumentException('Unknown comment subscription content type: ' . $contentType),
        };
    }

    private function table(): string
    {
        return $this->tablePrefix . 'comment_subscriptions';
    }
}
