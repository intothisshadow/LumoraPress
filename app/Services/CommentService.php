<?php

/**
 * Comment CRUD, moderation, and the public comment tree for a post.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Models\Comment;
use LumoraPress\Models\CommentStatus;
use RuntimeException;

/**
 * Comment CRUD, moderation, and the public comment tree for a post.
 *
 * Commenter identity is denormalized onto the comment row itself
 * (guest_name/guest_email/guest_url) rather than joined from
 * {prefix}users, even when user_id is set — a comment is a snapshot of
 * who posted it at the time, not a live reference, and this avoids an
 * N+1 join against UserService for every admin/public listing. user_id
 * is kept purely so moderation tooling can trace every comment back to
 * an account.
 *
 * Every query here uses a distinct placeholder name per occurrence, even
 * when binding the same value twice — see CategoryService's docblock and
 * PHP-TEST-SUITE.md's "Known gaps" for why this matters against a real
 * MySQL connection.
 *
 * $hooks is optional (LP-037) — see PostService's docblock for why.
 * create() deliberately does not fire a hook itself:
 * SiteController::submitComment() already fires 'comment_posted' after
 * calling it, and firing a second, redundant action here would double up
 * every listener a plugin (or LP-037's own cache invalidation) attaches.
 */
final class CommentService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    public function create(
        int $postId,
        ?int $parentId,
        ?int $userId,
        string $guestName,
        string $guestEmail,
        ?string $guestUrl,
        string $content,
        CommentStatus $status,
        ?string $ipAddress,
        ?string $userAgent,
    ): Comment {
        $now = new DateTimeImmutable();

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (post_id, parent_id, user_id, guest_name, guest_email, guest_url, content, status, ip_address, user_agent, created_at, updated_at)
             VALUES (:post_id, :parent_id, :user_id, :guest_name, :guest_email, :guest_url, :content, :status, :ip_address, :user_agent, :created_at, :updated_at)',
            [
                'post_id' => $postId,
                'parent_id' => $parentId,
                'user_id' => $userId,
                'guest_name' => $guestName,
                'guest_email' => $guestEmail,
                'guest_url' => $guestUrl,
                'content' => $content,
                'status' => $status->value,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ],
        );

        $comment = $this->findById((int) $id);

        if ($comment === null) {
            throw new RuntimeException('Failed to load the comment that was just created.');
        }

        return $comment;
    }

    public function updateContent(int $id, string $content): Comment
    {
        $this->database->execute(
            'UPDATE ' . $this->table() . ' SET content = :content, updated_at = :updated_at WHERE id = :id',
            ['content' => $content, 'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        );

        $comment = $this->findById($id);

        if ($comment === null) {
            throw new RuntimeException('Failed to load the comment that was just updated.');
        }

        return $comment;
    }

    public function updateStatus(int $id, CommentStatus $status): Comment
    {
        $this->database->execute(
            'UPDATE ' . $this->table() . ' SET status = :status, updated_at = :updated_at WHERE id = :id',
            ['status' => $status->value, 'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        );

        $comment = $this->findById($id);

        if ($comment === null) {
            throw new RuntimeException('Failed to load the comment that was just updated.');
        }

        $this->hooks?->doAction('comment_status_changed', $comment);

        return $comment;
    }

    /**
     * Orphans any replies (their parent_id becomes NULL rather than
     * cascading the delete to them, the same trade-off CategoryService
     * makes for child categories) then deletes the comment itself.
     */
    public function delete(int $id): bool
    {
        $deleted = (bool) $this->database->transaction(function () use ($id): int {
            $this->database->execute(
                'UPDATE ' . $this->table() . ' SET parent_id = NULL WHERE parent_id = :parent_id',
                ['parent_id' => $id],
            );

            return $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);
        });

        if ($deleted) {
            $this->hooks?->doAction('comment_deleted', $id);
        }

        return $deleted;
    }

    public function findById(int $id): ?Comment
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function countByStatus(CommentStatus $status): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE status = :status',
            ['status' => $status->value],
        );
    }

    public function countForPost(int $postId, CommentStatus $status = CommentStatus::Approved): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE post_id = :post_id AND status = :status',
            ['post_id' => $postId, 'status' => $status->value],
        );
    }

    /**
     * All comments for the admin moderation screen, newest first, each
     * paired with the title/slug of the post it belongs to (one JOIN
     * rather than one lookup per row).
     *
     * @return array{comments: array<int, array{comment: Comment, postTitle: string, postSlug: string}>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateForAdmin(int $page = 1, int $perPage = 20, ?CommentStatus $statusFilter = null): array
    {
        $page = max(1, $page);
        $where = $statusFilter !== null ? 'WHERE c.status = :status' : '';
        $params = $statusFilter !== null ? ['status' => $statusFilter->value] : [];

        $total = (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' c ' . $where,
            $params,
        );

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT c.*, p.title AS post_title, p.slug AS post_slug
               FROM ' . $this->table() . ' c
               INNER JOIN ' . $this->postsTable() . ' p ON p.id = c.post_id
               ' . $where . '
              ORDER BY c.created_at DESC, c.id DESC
              LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params,
        );

        return [
            'comments' => array_map($this->hydrateWithPost(...), $rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * @return array<int, array{comment: Comment, postTitle: string, postSlug: string}>
     */
    public function recentForAdmin(int $limit = 5): array
    {
        $rows = $this->database->fetchAll(
            'SELECT c.*, p.title AS post_title, p.slug AS post_slug
               FROM ' . $this->table() . ' c
               INNER JOIN ' . $this->postsTable() . ' p ON p.id = c.post_id
              ORDER BY c.created_at DESC, c.id DESC
              LIMIT ' . (int) $limit,
        );

        return array_map($this->hydrateWithPost(...), $rows);
    }

    /**
     * The most recent approved comments site-wide (LP-048, for the Recent
     * Comments widget) — unlike recentForAdmin(), which intentionally
     * includes every status for moderators, this must never surface a
     * pending/spam/trashed comment to public site visitors.
     *
     * @return array<int, array{comment: Comment, postTitle: string, postSlug: string}>
     */
    public function recentApproved(int $limit = 5): array
    {
        $rows = $this->database->fetchAll(
            'SELECT c.*, p.title AS post_title, p.slug AS post_slug
               FROM ' . $this->table() . ' c
               INNER JOIN ' . $this->postsTable() . ' p ON p.id = c.post_id
              WHERE c.status = :status
              ORDER BY c.created_at DESC, c.id DESC
              LIMIT ' . (int) $limit,
            ['status' => CommentStatus::Approved->value],
        );

        return array_map($this->hydrateWithPost(...), $rows);
    }

    /**
     * Approved comments for a post, built into a nested reply tree.
     * Oldest first within each level, matching the classic default
     * comment order.
     *
     * @return array<int, array{comment: Comment, children: array<mixed>}>
     */
    public function publicTreeForPost(int $postId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . '
                WHERE post_id = :post_id AND status = :status
             ORDER BY created_at ASC, id ASC',
            ['post_id' => $postId, 'status' => CommentStatus::Approved->value],
        );

        $comments = array_map($this->hydrate(...), $rows);

        return $this->buildTree($comments, null);
    }

    /**
     * "Comment author must have a previously approved comment" — the
     * lightweight, standard trust signal used to auto-approve without
     * requiring a login system for public commenters.
     */
    public function hasPreviouslyApprovedComment(?int $userId, ?string $guestEmail): bool
    {
        if ($userId !== null) {
            return $this->database->fetchOne(
                'SELECT id FROM ' . $this->table() . ' WHERE user_id = :user_id AND status = :status',
                ['user_id' => $userId, 'status' => CommentStatus::Approved->value],
            ) !== null;
        }

        if ($guestEmail === null || $guestEmail === '') {
            return false;
        }

        return $this->database->fetchOne(
            'SELECT id FROM ' . $this->table() . ' WHERE LOWER(guest_email) = LOWER(:guest_email) AND status = :status',
            ['guest_email' => $guestEmail, 'status' => CommentStatus::Approved->value],
        ) !== null;
    }

    /**
     * Basic flood control: rejects a submission if this IP has posted a
     * comment (any status) within the last $windowSeconds.
     */
    public function recentCommentFromIpExists(string $ipAddress, int $windowSeconds): bool
    {
        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);

        return $this->database->fetchOne(
            'SELECT id FROM ' . $this->table() . ' WHERE ip_address = :ip_address AND created_at >= :window_start',
            ['ip_address' => $ipAddress, 'window_start' => $windowStart],
        ) !== null;
    }

    /**
     * @param array<int, Comment> $comments
     * @return array<int, array{comment: Comment, children: array<mixed>}>
     */
    private function buildTree(array $comments, ?int $parentId): array
    {
        $branch = [];

        foreach ($comments as $comment) {
            if ($comment->parentId !== $parentId) {
                continue;
            }

            $branch[] = [
                'comment' => $comment,
                'children' => $this->buildTree($comments, $comment->id),
            ];
        }

        return $branch;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Comment
    {
        return new Comment(
            id: (int) $row['id'],
            postId: (int) $row['post_id'],
            parentId: $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            userId: $row['user_id'] !== null ? (int) $row['user_id'] : null,
            guestName: (string) $row['guest_name'],
            guestEmail: (string) $row['guest_email'],
            guestUrl: $row['guest_url'] !== null ? (string) $row['guest_url'] : null,
            content: (string) $row['content'],
            status: CommentStatus::from((string) $row['status']),
            ipAddress: $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
            userAgent: $row['user_agent'] !== null ? (string) $row['user_agent'] : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{comment: Comment, postTitle: string, postSlug: string}
     */
    private function hydrateWithPost(array $row): array
    {
        return [
            'comment' => $this->hydrate($row),
            'postTitle' => (string) $row['post_title'],
            'postSlug' => (string) $row['post_slug'],
        ];
    }

    private function table(): string
    {
        return $this->tablePrefix . 'comments';
    }

    private function postsTable(): string
    {
        return $this->tablePrefix . 'posts';
    }
}
