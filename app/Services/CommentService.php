<?php

/**
 * Comment CRUD, moderation, and the public comment tree for a post or page.
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
use LumoraPress\Models\PageStatus;
use LumoraPress\Models\PageVisibility;
use LumoraPress\Models\PostStatus;
use LumoraPress\Models\PostVisibility;
use RuntimeException;

/**
 * Comment CRUD, moderation, and the public comment tree for a post or page.
 *
 * Commenter identity is denormalized onto the comment row itself (guest_name/guest_email/
 * guest_url) even when user_id is set — a comment is a snapshot of who posted it, not a live
 * reference, avoiding an N+1 join for every listing. user_id is kept only for moderation
 * tooling to trace a comment back to an account. See CategoryService's docblock for why
 * every query uses a distinct placeholder name per occurrence.
 *
 * create() deliberately does not fire a hook itself: SiteController::submitComment() already
 * fires 'comment_posted' after calling it, so firing a second one here would double up
 * every listener.
 */
final class CommentService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    /**
     * Exactly one of $postId/$pageId should be passed — see Comment's
     * own docblock for why this is a nullable pair rather than a
     * polymorphic content_id/content_type column.
     *
     * $commentedAt lets a bulk importer (e.g. a WordPress import)
     * preserve a source comment's original date instead of always
     * stamping "now" — every other caller leaves it null.
     */
    public function create(
        ?int $postId,
        ?int $parentId,
        ?int $userId,
        string $guestName,
        string $guestEmail,
        ?string $guestUrl,
        string $content,
        CommentStatus $status,
        ?string $ipAddress,
        ?string $userAgent,
        ?int $pageId = null,
        ?DateTimeImmutable $commentedAt = null,
    ): Comment {
        $now = $commentedAt ?? new DateTimeImmutable();

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (post_id, page_id, parent_id, user_id, guest_name, guest_email, guest_url, content, status, ip_address, user_agent, created_at, updated_at)
             VALUES (:post_id, :page_id, :parent_id, :user_id, :guest_name, :guest_email, :guest_url, :content, :status, :ip_address, :user_agent, :created_at, :updated_at)',
            [
                'post_id' => $postId,
                'page_id' => $pageId,
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

    /**
     * Permanently deletes every comment currently at the given status via
     * delete(), so each one gets the same reply-orphaning cleanup a
     * single per-comment delete would — not a bare bulk DELETE. Unlike
     * Categories/Posts/Pages, Trash here is a status value rather than a
     * trashed_at column (see this class's own docblock on Comment status).
     * Backs both emptyTrash() (Trash) and the Empty Spam action (Spam) —
     * there is nothing Trash-specific about this implementation.
     *
     * @return int how many comments were removed
     */
    public function emptyByStatus(CommentStatus $status): int
    {
        $matchingIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->fetchAll(
                'SELECT id FROM ' . $this->table() . ' WHERE status = :status',
                ['status' => $status->value],
            ),
        );

        $removed = 0;

        foreach ($matchingIds as $matchingId) {
            if ($this->delete($matchingId)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @return int how many comments were removed
     */
    public function emptyTrash(): int
    {
        return $this->emptyByStatus(CommentStatus::Trash);
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
     * Batched countForPost() for a listing page — one query for every
     * post shown rather than one per row. Missing/zero-comment post ids
     * are simply absent from the result rather than present with 0, so
     * callers should read it via ($counts[$postId] ?? 0).
     *
     * @param array<int, int> $postIds
     * @return array<int, int>
     */
    public function countsForPosts(array $postIds, CommentStatus $status = CommentStatus::Approved): array
    {
        $postIds = array_values(array_unique(array_map('intval', $postIds)));

        if ($postIds === []) {
            return [];
        }

        $placeholders = [];
        $params = ['status' => $status->value];

        foreach ($postIds as $i => $postId) {
            $key = "post_id_{$i}";
            $placeholders[] = ':' . $key;
            $params[$key] = $postId;
        }

        $rows = $this->database->fetchAll(
            'SELECT post_id, COUNT(*) AS comment_count FROM ' . $this->table()
            . ' WHERE post_id IN (' . implode(',', $placeholders) . ') AND status = :status'
            . ' GROUP BY post_id',
            $params,
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['post_id']] = (int) $row['comment_count'];
        }

        return $counts;
    }

    /**
     * Mirrors countForPost() exactly, for Pages.
     */
    public function countForPage(int $pageId, CommentStatus $status = CommentStatus::Approved): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE page_id = :page_id AND status = :status',
            ['page_id' => $pageId, 'status' => $status->value],
        );
    }

    /**
     * All comments for the admin moderation screen, newest first, each
     * paired with the title/slug of whichever post or page it belongs
     * to (one LEFT JOIN per content type rather than one lookup per
     * row — LEFT, not INNER, since exactly one of post_id/page_id is
     * ever set per comment, see Comment's own docblock).
     *
     * @return array{comments: array<int, array{comment: Comment, contentTitle: string, contentSlug: string, contentType: string}>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateForAdmin(int $page = 1, int $perPage = 20, ?CommentStatus $statusFilter = null): array
    {
        $page = max(1, $page);

        // Matches PostService::paginateForAdmin()'s "All excludes Trash"
        // convention, so the Comments screen's "All" tab count can be
        // stated as a sum of the other three tabs' own status labels.
        $where = $statusFilter !== null ? 'WHERE c.status = :status' : "WHERE c.status != 'trash'";
        $params = $statusFilter !== null ? ['status' => $statusFilter->value] : [];

        $total = (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' c ' . $where,
            $params,
        );

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT c.*, p.title AS post_title, p.slug AS post_slug, pg.title AS page_title, pg.slug AS page_slug
               FROM ' . $this->table() . ' c
               LEFT JOIN ' . $this->postsTable() . ' p ON p.id = c.post_id
               LEFT JOIN ' . $this->pagesTable() . ' pg ON pg.id = c.page_id
               ' . $where . '
              ORDER BY c.created_at DESC, c.id DESC
              LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params,
        );

        return [
            'comments' => array_map($this->hydrateWithContent(...), $rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * @return array<int, array{comment: Comment, contentTitle: string, contentSlug: string, contentType: string}>
     */
    public function recentForAdmin(int $limit = 5): array
    {
        $rows = $this->database->fetchAll(
            'SELECT c.*, p.title AS post_title, p.slug AS post_slug, pg.title AS page_title, pg.slug AS page_slug
               FROM ' . $this->table() . ' c
               LEFT JOIN ' . $this->postsTable() . ' p ON p.id = c.post_id
               LEFT JOIN ' . $this->pagesTable() . ' pg ON pg.id = c.page_id
              ORDER BY c.created_at DESC, c.id DESC
              LIMIT ' . (int) $limit,
        );

        return array_map($this->hydrateWithContent(...), $rows);
    }

    /**
     * The most recent approved comments site-wide (for the Recent
     * Comments widget and the site-wide comments feed) — unlike
     * recentForAdmin(), which intentionally includes every status for
     * moderators, this must never surface a pending/spam/trashed comment
     * to public site visitors. Also excludes a comment whose post/page is
     * itself not publicly visible (trashed, draft, scheduled-not-yet-due,
     * or Private) — trashing a post doesn't delete its comments (only a
     * permanent delete does, see PostService::delete()), so without this
     * a private or trashed post's approved comments would otherwise still
     * leak here even though the post itself is correctly gated elsewhere.
     *
     * @return array<int, array{comment: Comment, contentTitle: string, contentSlug: string, contentType: string}>
     */
    public function recentApproved(int $limit = 5): array
    {
        $rows = $this->database->fetchAll(
            'SELECT c.*, p.title AS post_title, p.slug AS post_slug, pg.title AS page_title, pg.slug AS page_slug
               FROM ' . $this->table() . ' c
               LEFT JOIN ' . $this->postsTable() . ' p ON p.id = c.post_id
               LEFT JOIN ' . $this->pagesTable() . ' pg ON pg.id = c.page_id
              WHERE c.status = :status
                AND (
                    (c.post_id IS NOT NULL AND p.status = :post_status AND p.visibility = :post_visibility)
                    OR (c.page_id IS NOT NULL AND pg.status = :page_status AND pg.visibility = :page_visibility)
                )
              ORDER BY c.created_at DESC, c.id DESC
              LIMIT ' . (int) $limit,
            [
                'status' => CommentStatus::Approved->value,
                'post_status' => PostStatus::Published->value,
                'post_visibility' => PostVisibility::Public->value,
                'page_status' => PageStatus::Published->value,
                'page_visibility' => PageVisibility::Public->value,
            ],
        );

        return array_map($this->hydrateWithContent(...), $rows);
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
     * Mirrors publicTreeForPost() exactly, for Pages.
     *
     * @return array<int, array{comment: Comment, children: array<mixed>}>
     */
    public function publicTreeForPage(int $pageId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . '
                WHERE page_id = :page_id AND status = :status
             ORDER BY created_at ASC, id ASC',
            ['page_id' => $pageId, 'status' => CommentStatus::Approved->value],
        );

        $comments = array_map($this->hydrate(...), $rows);

        return $this->buildTree($comments, null);
    }

    /**
     * Approved comments for a post as a paginated, orderable thread list. Pagination counts
     * top-level threads (a comment plus its nested replies is one page entry), not raw row
     * count, so a reply is never split from its parent mid-thread. When $threaded is false,
     * nesting is ignored and every comment paginates as its own flat entry.
     *
     * @return array{comments: array<int, array{comment: Comment, children: array<mixed>}>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateForPost(int $postId, int $page = 1, int $perPage = 50, string $order = 'asc', bool $threaded = true): array
    {
        $direction = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . "
                WHERE post_id = :post_id AND status = :status
             ORDER BY created_at {$direction}, id {$direction}",
            ['post_id' => $postId, 'status' => CommentStatus::Approved->value],
        );

        $comments = array_map($this->hydrate(...), $rows);

        $entries = $threaded
            ? $this->buildTree($comments, null)
            : array_map(static fn (Comment $comment): array => ['comment' => $comment, 'children' => []], $comments);

        $total = count($entries);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        return [
            'comments' => array_slice($entries, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Mirrors paginateForPost() exactly, for Pages.
     *
     * @return array{comments: array<int, array{comment: Comment, children: array<mixed>}>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateForPage(int $pageId, int $page = 1, int $perPage = 50, string $order = 'asc', bool $threaded = true): array
    {
        $direction = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . "
                WHERE page_id = :page_id AND status = :status
             ORDER BY created_at {$direction}, id {$direction}",
            ['page_id' => $pageId, 'status' => CommentStatus::Approved->value],
        );

        $comments = array_map($this->hydrate(...), $rows);

        $entries = $threaded
            ? $this->buildTree($comments, null)
            : array_map(static fn (Comment $comment): array => ['comment' => $comment, 'children' => []], $comments);

        $total = count($entries);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        return [
            'comments' => array_slice($entries, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
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
     * Same window as recentCommentFromIpExists(), but a count rather than
     * a single-window existence check — for a graduated posting-frequency
     * signal (used by anti-spam plugins) rather than a flat yes/no.
     */
    public function countRecentFromIp(string $ipAddress, int $windowSeconds): int
    {
        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);

        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE ip_address = :ip_address AND created_at >= :window_start',
            ['ip_address' => $ipAddress, 'window_start' => $windowStart],
        );
    }

    /**
     * Mirrors hasPreviouslyApprovedComment()'s exact user_id/guest_email
     * preference and case-insensitive email match, checking Spam status
     * instead of Approved — a prior-spam trust signal for anti-spam
     * plugins.
     */
    public function hasPreviousSpamHistory(?int $userId, ?string $guestEmail): bool
    {
        if ($userId !== null) {
            return $this->database->fetchOne(
                'SELECT id FROM ' . $this->table() . ' WHERE user_id = :user_id AND status = :status',
                ['user_id' => $userId, 'status' => CommentStatus::Spam->value],
            ) !== null;
        }

        if ($guestEmail === null || $guestEmail === '') {
            return false;
        }

        return $this->database->fetchOne(
            'SELECT id FROM ' . $this->table() . ' WHERE LOWER(guest_email) = LOWER(:guest_email) AND status = :status',
            ['guest_email' => $guestEmail, 'status' => CommentStatus::Spam->value],
        ) !== null;
    }

    /**
     * Whether this exact content string already exists as some other
     * comment (any status, any post/page) — classic copy-pasted-spam
     * behavior. Not scoped to "a *different* post" specifically: the
     * 'comment_is_spam' filter's signature carries no post/page ID at
     * all, so a plugin calling this from that filter has no way to
     * exclude "the post being commented on right now" in the first
     * place — an exact byte-for-byte match already present anywhere is
     * suspicious enough on its own for a brand-new submission.
     */
    public function identicalContentExists(string $content): bool
    {
        return $this->database->fetchOne(
            'SELECT id FROM ' . $this->table() . ' WHERE content = :content',
            ['content' => $content],
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
            postId: $row['post_id'] !== null ? (int) $row['post_id'] : null,
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
            pageId: isset($row['page_id']) && $row['page_id'] !== null ? (int) $row['page_id'] : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{comment: Comment, contentTitle: string, contentSlug: string, contentType: string}
     */
    private function hydrateWithContent(array $row): array
    {
        $isPage = $row['page_id'] !== null;

        return [
            'comment' => $this->hydrate($row),
            'contentTitle' => (string) ($isPage ? $row['page_title'] : $row['post_title']),
            'contentSlug' => (string) ($isPage ? $row['page_slug'] : $row['post_slug']),
            'contentType' => $isPage ? 'page' : 'post',
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

    private function pagesTable(): string
    {
        return $this->tablePrefix . 'pages';
    }
}
